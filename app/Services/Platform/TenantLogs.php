<?php

namespace App\Services\Platform;

use App\Models\Application;
use App\Models\PlatformRegion;
use App\Models\Server;
use App\Models\Service;
use App\Providers\PlatformServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

/**
 * Log history of the tenant resources (wiki 9, "Limitações de Logs e Monitoramento").
 *
 * Alloy ships every container log of the nodes to the regional Loki with the tenant_id label set
 * by the platform. The dashboard builds the whole LogQL query itself: the stream selector always
 * pins the tenant and the resource, and the optional text filter is a literal line filter, so a
 * tenant can never read another tenant's logs, whatever it types.
 */
class TenantLogs
{
    /** @var array<string, int> range => minutes */
    public const RANGES = ['15m' => 15, '1h' => 60, '6h' => 360, '24h' => 1440, '7d' => 10080, '30d' => 43200];

    public const MAX_FILTER_LENGTH = 200;

    /**
     * Resources of a team that run containers, for the resource picker.
     *
     * @return array<string, string> uuid => label
     */
    public static function resourceOptions(int $teamId): array
    {
        $options = [];
        foreach (PlatformServiceProvider::workloadModels() as $model) {
            $model::query()
                ->whereRelation('environment.project', 'team_id', $teamId)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'uuid', 'name', 'environment_id'])
                ->each(function (Model $resource) use (&$options) {
                    $options[$resource->getAttribute('uuid')] = $resource->getAttribute('name').' ('.self::typeLabel($resource).')';
                });
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * A resource of the team, or null (also for resources of other teams).
     */
    public static function findResource(int $teamId, string $uuid): ?Model
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $uuid)) {
            return null;
        }
        $resource = getResourceByUuid($uuid, $teamId);

        return $resource instanceof Model && in_array($resource::class, PlatformServiceProvider::workloadModels(), true) ? $resource : null;
    }

    /**
     * LogQL query for the containers of one resource of one tenant.
     */
    public static function logQuery(int $teamId, string $uuid, string $filter = ''): string
    {
        if ($teamId <= 0 || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $uuid)) {
            throw new \InvalidArgumentException('Invalid tenant or resource.');
        }
        // Containers are named <uuid>-<suffix> (applications), <uuid> (databases) or <name>-<uuid> (services).
        $query = '{tenant_id="'.$teamId.'", container=~".*'.$uuid.'.*"}';
        $filter = self::validateFilter($filter);
        if ($filter !== '') {
            $query .= ' |= `'.$filter.'`';
        }

        return $query;
    }

    /**
     * A literal text filter: raw LogQL string (backticks), so nothing in it is interpreted.
     */
    public static function validateFilter(string $filter): string
    {
        $filter = trim($filter);
        if (mb_strlen($filter) > self::MAX_FILTER_LENGTH) {
            throw new \InvalidArgumentException(__('The filter can have at most :max characters.', ['max' => self::MAX_FILTER_LENGTH]));
        }
        if (str_contains($filter, '`') || preg_match('/[\x00-\x1F\x7F]/', $filter)) {
            throw new \InvalidArgumentException(__('The filter can not contain backticks or control characters.'));
        }

        return $filter;
    }

    /**
     * Loki address of the region where the resource runs.
     */
    public static function lokiUrlFor(Model $resource): ?string
    {
        $server = self::serverOf($resource);
        $region = $server?->platform_region_id ? PlatformRegion::find($server->platform_region_id) : PlatformRegion::defaultRegion();
        $url = filled($region?->logs_url) ? (string) $region->logs_url : (string) config('platform.logs.loki_url');
        if ($url === '') {
            return null;
        }

        return rtrim(str_replace('{region}', (string) $region?->slug, $url), '/');
    }

    /**
     * Last lines of a resource, oldest first.
     *
     * @return array{lines: list<array{time: string, container: string, line: string}>, error: ?string}
     */
    public static function fetch(int $teamId, Model $resource, string $range = '1h', string $filter = '', int $limit = 500): array
    {
        $minutes = self::RANGES[$range] ?? null;
        if (is_null($minutes)) {
            return ['lines' => [], 'error' => __('Invalid time range.')];
        }
        try {
            $query = self::logQuery($teamId, (string) $resource->getAttribute('uuid'), $filter);
        } catch (\InvalidArgumentException $e) {
            return ['lines' => [], 'error' => $e->getMessage()];
        }
        $url = self::lokiUrlFor($resource);
        if (is_null($url)) {
            return ['lines' => [], 'error' => __('The log history of this region is not configured yet.')];
        }
        $limit = max(1, min($limit, (int) config('platform.logs.max_lines', 1000)));
        $end = now();
        try {
            $response = Http::timeout(max(1, (int) config('platform.logs.timeout', 10)))
                ->acceptJson()
                ->get($url.'/loki/api/v1/query_range', [
                    'query' => $query,
                    'start' => $end->copy()->subMinutes($minutes)->getTimestampMs() * 1000000,
                    'end' => $end->getTimestampMs() * 1000000,
                    'limit' => $limit,
                    'direction' => 'backward',
                ]);
        } catch (\Throwable $e) {
            report($e);

            return ['lines' => [], 'error' => __('The log history could not be read right now. Try again in a moment.')];
        }
        if (! $response->successful()) {
            return ['lines' => [], 'error' => __('The log history could not be read right now. Try again in a moment.')];
        }
        $lines = [];
        foreach ((array) $response->json('data.result', []) as $stream) {
            $container = (string) data_get($stream, 'stream.container', '');
            foreach ((array) data_get($stream, 'values', []) as $value) {
                if (! is_array($value) || count($value) < 2) {
                    continue;
                }
                $lines[] = ['ns' => (string) $value[0], 'container' => $container, 'line' => (string) $value[1]];
            }
        }
        usort($lines, fn (array $a, array $b) => strcmp(str_pad($b['ns'], 20, '0', STR_PAD_LEFT), str_pad($a['ns'], 20, '0', STR_PAD_LEFT)));
        $lines = array_reverse(array_slice($lines, 0, $limit));

        return [
            'lines' => array_map(fn (array $line) => [
                'time' => \Illuminate\Support\Carbon::createFromTimestampMs(intdiv((int) $line['ns'], 1000000))->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                'container' => $line['container'],
                'line' => rtrim($line['line'], "\n"),
            ], $lines),
            'error' => null,
        ];
    }

    private static function serverOf(Model $resource): ?Server
    {
        if ($resource instanceof Service) {
            return $resource->server;
        }
        $server = data_get($resource, 'destination.server');

        return $server instanceof Server ? $server : null;
    }

    private static function typeLabel(Model $resource): string
    {
        return match (true) {
            $resource instanceof Application => 'Application',
            $resource instanceof Service => 'Service',
            default => str(class_basename($resource))->after('Standalone')->value(),
        };
    }
}
