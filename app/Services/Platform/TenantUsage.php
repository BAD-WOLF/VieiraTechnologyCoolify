<?php

namespace App\Services\Platform;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PlatformTenant;
use App\Models\UsageEvent;
use Carbon\CarbonInterface;

/**
 * Current consumption of a tenant against its plan (dashboards, enforcement and the usage API).
 */
class TenantUsage
{
    /**
     * @return array<string, array{used: float|int, limit: float|int, percent: float|null}>
     */
    public static function snapshot(PlatformTenant $tenant): array
    {
        $quotas = $tenant->quotas();
        $allocated = PlatformGate::allocated($tenant->team_id, $quotas);
        [$periodStart, $periodEnd] = self::currentPeriod($tenant);

        // The containers share the plan on each node, so what matters to the customer is what they
        // use (metering), not the limits they declared.
        $inUse = TenantLimits::sharesPlan();
        $metrics = [
            'cpus' => [$inUse ? self::cpusInUse($tenant->team_id) : $allocated['cpus'], (float) $quotas['cpus']],
            'memory_mb' => [$inUse ? self::memoryInUseMb($tenant->team_id) : $allocated['memory_mb'], (int) $quotas['memory_mb']],
            'containers' => [$allocated['containers'], (int) $quotas['containers']],
            'resources' => [PlatformGate::resourceCount($tenant->team_id), (int) $quotas['resources']],
            'storage_gb' => [round(self::latestStorageBytes($tenant->team_id) / 1_000_000_000, 2), (int) $quotas['storage_gb']],
            'egress_gb' => [round(self::sum($tenant->team_id, UsageEvent::METRIC_EGRESS_BYTES, $periodStart, $periodEnd) / 1_000_000_000, 2), (int) $quotas['egress_gb']],
            'deployments_per_day' => [self::deploymentsLastDay($tenant->team_id), (int) $quotas['deployments_per_day']],
            'custom_domains' => [count(array_filter(DomainPolicy::hostsOwnedBy($tenant->team_id), fn ($host) => DomainPolicy::isCustomHost($host))), (int) $quotas['custom_domains']],
        ];

        $snapshot = [];
        foreach ($metrics as $key => [$used, $limit]) {
            $snapshot[$key] = [
                'used' => $used,
                'limit' => $limit,
                'percent' => $limit > 0 ? round(($used / $limit) * 100, 1) : null,
            ];
        }

        return $snapshot;
    }

    /**
     * Billing period from Stripe when known, calendar month otherwise.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public static function currentPeriod(PlatformTenant $tenant): array
    {
        if ($tenant->current_period_start && $tenant->current_period_end && $tenant->current_period_end->isFuture()) {
            return [$tenant->current_period_start, $tenant->current_period_end];
        }

        return [now()->startOfMonth(), now()->endOfMonth()];
    }

    public static function sum(int $teamId, string $metric, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('metric', $metric)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<', $to)
            ->sum('value');
    }

    /**
     * Storage of the most recent collection run (sum of the per-resource gauges).
     */
    public static function latestStorageBytes(int $teamId): float
    {
        $latest = UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('metric', UsageEvent::METRIC_STORAGE_BYTES)
            ->where('recorded_at', '>=', now()->subDay())
            ->max('recorded_at');
        if (is_null($latest)) {
            return 0.0;
        }

        return (float) UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('metric', UsageEvent::METRIC_STORAGE_BYTES)
            ->where('recorded_at', $latest)
            ->sum('value');
    }

    /**
     * vCPUs used by the containers of the team at the last collection (sum over its nodes).
     */
    public static function cpusInUse(int $teamId): float
    {
        return round(self::latestRate($teamId, UsageEvent::METRIC_CPU_SECONDS), 2);
    }

    public static function memoryInUseMb(int $teamId): int
    {
        return (int) round(self::latestRate($teamId, UsageEvent::METRIC_MEMORY_BYTE_SECONDS) / 1_048_576);
    }

    /**
     * Value per second of a metric collected per interval (CPU seconds, memory byte-seconds) at
     * the last collection of the team.
     */
    private static function latestRate(int $teamId, string $metric): float
    {
        $interval = max(60, (int) config('platform.metering.interval_minutes', 5) * 60);
        $latest = UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('metric', $metric)
            ->where('recorded_at', '>=', now()->subSeconds($interval * 3))
            ->max('recorded_at');
        if (is_null($latest)) {
            return 0.0;
        }

        return (float) UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('metric', $metric)
            ->where('recorded_at', $latest)
            ->sum('value') / $interval;
    }

    public static function deploymentsLastDay(int $teamId): int
    {
        $applicationIds = Application::query()->withoutGlobalScopes()
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))
            ->pluck('id');

        return ApplicationDeploymentQueue::query()
            ->whereIn('application_id', $applicationIds)
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    /**
     * Quotas whose use is counted against the plan. CPU and memory are shared on each node and
     * capped by the tenant slice (what the containers use can never pass the plan there), so they
     * only count when every container limit counts in full.
     *
     * @return array<int, string>
     */
    public static function hardLimits(): array
    {
        return TenantLimits::sharesPlan()
            ? ['containers', 'resources', 'storage_gb']
            : ['cpus', 'memory_mb', 'containers', 'resources', 'storage_gb'];
    }

    /**
     * Highest utilization (percent) among the quotas that are enforced as hard limits.
     */
    public static function peakUtilization(array $snapshot): float
    {
        $peak = 0.0;
        foreach (self::hardLimits() as $key) {
            $peak = max($peak, (float) ($snapshot[$key]['percent'] ?? 0));
        }

        return $peak;
    }
}
