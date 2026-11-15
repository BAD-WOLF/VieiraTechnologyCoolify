<?php

namespace App\Services\Platform;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\UsageEvent;
use Illuminate\Support\Facades\Cache;

/**
 * Metering of tenant containers on a node (wiki 8: instrumentation -> usage_events).
 *
 * One SSH round trip per node collects container ownership (label set by the platform hardening),
 * docker stats (CPU %, memory, network tx) and, once per hour, storage per volume/directory.
 * Samples are converted into usage events per tenant and resource.
 */
class UsageCollector
{
    public const PS_MARKER = '__PLATFORM_PS__';

    public const STATS_MARKER = '__PLATFORM_STATS__';

    public const STORAGE_MARKER = '__PLATFORM_STORAGE__';

    /**
     * @return array<int, string>
     */
    public static function commands(bool $withStorage): array
    {
        $commands = [
            'echo '.self::PS_MARKER,
            "docker ps --no-trunc --format '{{.ID}}\t{{.Names}}\t{{.Label \"coolify.tenantId\"}}'",
            'echo '.self::STATS_MARKER,
            "docker stats --no-stream --no-trunc --format '{{.ID}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}'",
        ];
        if ($withStorage) {
            $base = base_configuration_dir();
            $commands[] = 'echo '.self::STORAGE_MARKER;
            $commands[] = 'for v in $(docker volume ls -q); do p=$(docker volume inspect -f \'{{.Mountpoint}}\' "$v" 2>/dev/null); s=$(du -sb "$p" 2>/dev/null | cut -f1); printf \'volume\t%s\t%s\n\' "$v" "${s:-0}"; done';
            $commands[] = "for d in {$base}/applications/* {$base}/services/* {$base}/databases/*; do [ -d \"\$d\" ] && printf 'dir\\t%s\\t%s\\n' \"\$(basename \"\$d\")\" \"\$(du -sb \"\$d\" 2>/dev/null | cut -f1)\"; done; true";
        }

        return $commands;
    }

    /**
     * @return array{containers: array<string, array{name: string, tenant: ?int}>, stats: array<string, array{cpu_percent: float, memory_bytes: int, net_tx_bytes: int}>, volumes: array<string, int>, directories: array<string, int>}
     */
    public static function parse(?string $output): array
    {
        $result = ['containers' => [], 'stats' => [], 'volumes' => [], 'directories' => []];
        $section = null;
        foreach (preg_split('/\r\n|\n|\r/', (string) $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (in_array($line, [self::PS_MARKER, self::STATS_MARKER, self::STORAGE_MARKER], true)) {
                $section = $line;

                continue;
            }
            $parts = explode("\t", $line);
            if ($section === self::PS_MARKER && count($parts) >= 2) {
                $tenant = isset($parts[2]) && ctype_digit(trim($parts[2])) ? (int) trim($parts[2]) : null;
                $result['containers'][$parts[0]] = ['name' => ltrim($parts[1], '/'), 'tenant' => $tenant];
            } elseif ($section === self::STATS_MARKER && count($parts) >= 4) {
                $result['stats'][$parts[0]] = [
                    'cpu_percent' => (float) rtrim($parts[1], '%'),
                    'memory_bytes' => self::toBytes(trim(explode('/', $parts[2])[0])),
                    'net_tx_bytes' => self::toBytes(trim(explode('/', $parts[3])[1] ?? '0')),
                ];
            } elseif ($section === self::STORAGE_MARKER && count($parts) >= 3) {
                $bytes = ctype_digit(trim($parts[2])) ? (int) trim($parts[2]) : 0;
                if ($parts[0] === 'volume') {
                    $result['volumes'][$parts[1]] = $bytes;
                } elseif ($parts[0] === 'dir') {
                    $result['directories'][$parts[1]] = $bytes;
                }
            }
        }

        return $result;
    }

    /**
     * Docker sizes: "1.5GiB", "12.3MB", "0B", "512kB".
     */
    public static function toBytes(string $value): int
    {
        if (! preg_match('/^([\d.]+)\s*([kKmMgGtT]?)(i?)[bB]?$/', trim($value), $matches)) {
            return 0;
        }
        $base = $matches[3] === 'i' ? 1024 : 1000;
        $power = match (strtolower($matches[2])) {
            'k' => 1,
            'm' => 2,
            'g' => 3,
            't' => 4,
            default => 0,
        };

        return (int) round((float) $matches[1] * ($base ** $power));
    }

    /**
     * Collect on a node and store the usage events. Returns the number of events written.
     */
    public static function collect(Server $server, bool $withStorage = false): int
    {
        $output = instant_remote_process(self::commands($withStorage), $server, false);
        $now = now();
        $lastRunKey = "platform:metering:{$server->id}:last";
        $last = Cache::get($lastRunKey);
        $interval = $last ? max(60, min(900, $now->getTimestamp() - (int) $last)) : (int) config('platform.metering.interval_minutes', 5) * 60;
        Cache::put($lastRunKey, $now->getTimestamp(), now()->addDay());

        $events = self::events(self::parse($output), $server, $interval, $now->toDateTimeString());
        foreach (array_chunk($events, 500) as $chunk) {
            UsageEvent::query()->insert($chunk);
        }

        return count($events);
    }

    /**
     * @param  array{containers: array<string, array{name: string, tenant: ?int}>, stats: array<string, array{cpu_percent: float, memory_bytes: int, net_tx_bytes: int}>, volumes: array<string, int>, directories: array<string, int>}  $sample
     * @return array<int, array<string, mixed>>
     */
    public static function events(array $sample, Server $server, int $interval, string $recordedAt): array
    {
        $resources = self::tenantResources();
        $region = $server->platformRegion?->slug;
        $totals = [];
        $add = function (int $teamId, ?array $resource, string $metric, float $value) use (&$totals) {
            $key = $teamId.'|'.($resource['type'] ?? '').'|'.($resource['id'] ?? '').'|'.$metric;
            $totals[$key] ??= ['team_id' => $teamId, 'resource' => $resource, 'metric' => $metric, 'value' => 0.0];
            $totals[$key]['value'] += $value;
        };

        foreach ($sample['stats'] as $containerId => $stats) {
            $container = $sample['containers'][$containerId] ?? null;
            $resource = $container ? self::matchResource($container['name'], $resources) : null;
            $teamId = $container['tenant'] ?? $resource['team_id'] ?? null;
            if (is_null($teamId) || $teamId === 0) {
                continue;
            }
            $add($teamId, $resource, UsageEvent::METRIC_CPU_SECONDS, round($stats['cpu_percent'] / 100 * $interval, 3));
            $add($teamId, $resource, UsageEvent::METRIC_MEMORY_BYTE_SECONDS, (float) $stats['memory_bytes'] * $interval);
            $add($teamId, $resource, UsageEvent::METRIC_EGRESS_BYTES, (float) self::egressDelta($server->id, $containerId, $stats['net_tx_bytes']));
        }
        foreach ($sample['volumes'] as $name => $bytes) {
            $resource = self::matchResource($name, $resources);
            if ($resource) {
                $add($resource['team_id'], $resource, UsageEvent::METRIC_STORAGE_BYTES, (float) $bytes);
            }
        }
        foreach ($sample['directories'] as $uuid => $bytes) {
            $resource = $resources[$uuid] ?? null;
            if ($resource) {
                $add($resource['team_id'], $resource, UsageEvent::METRIC_STORAGE_BYTES, (float) $bytes);
            }
        }

        $rows = [];
        foreach ($totals as $total) {
            if ($total['metric'] !== UsageEvent::METRIC_STORAGE_BYTES && $total['value'] <= 0) {
                continue;
            }
            $rows[] = [
                'team_id' => $total['team_id'],
                'resource_type' => $total['resource']['type'] ?? null,
                'resource_id' => $total['resource']['id'] ?? null,
                'server_id' => $server->id,
                'region' => $region,
                'metric' => $total['metric'],
                'value' => $total['value'],
                'recorded_at' => $recordedAt,
                'metadata' => json_encode(['interval' => $interval]),
                'created_at' => $recordedAt,
            ];
        }

        return $rows;
    }

    private static function egressDelta(int $serverId, string $containerId, int $txBytes): int
    {
        $key = "platform:metering:{$serverId}:tx:{$containerId}";
        $previous = Cache::get($key);
        Cache::put($key, $txBytes, now()->addDay());
        if (is_null($previous)) {
            return 0;
        }

        return $txBytes >= (int) $previous ? $txBytes - (int) $previous : $txBytes;
    }

    /**
     * @param  array<string, array{team_id: int, type: string, id: int}>  $resources
     * @return array{team_id: int, type: string, id: int}|null
     */
    public static function matchResource(string $name, array $resources): ?array
    {
        foreach ($resources as $uuid => $resource) {
            if (str_contains($name, (string) $uuid)) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * uuid => owner map of every tenant workload (cached for a few minutes).
     *
     * @return array<string, array{team_id: int, type: string, id: int}>
     */
    public static function tenantResources(): array
    {
        return Cache::remember('platform:metering:resources', 240, function () {
            $map = [];
            $models = array_merge([Application::class, Service::class], PlatformGate::databaseModels());
            foreach ($models as $model) {
                $query = $model === Application::class ? Application::query()->withoutGlobalScopes() : $model::query();
                $query->with('environment.project:id,team_id')->select(['id', 'uuid', 'environment_id'])->chunk(500, function ($rows) use (&$map, $model) {
                    foreach ($rows as $row) {
                        $teamId = (int) data_get($row, 'environment.project.team_id');
                        if ($teamId === 0) {
                            continue;
                        }
                        $map[$row->uuid] = ['team_id' => $teamId, 'type' => $model, 'id' => $row->id];
                    }
                });
            }

            return $map;
        });
    }
}
