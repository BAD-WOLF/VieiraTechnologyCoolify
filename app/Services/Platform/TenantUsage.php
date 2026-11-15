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
        $allocated = PlatformGate::allocated($tenant->team_id);
        [$periodStart, $periodEnd] = self::currentPeriod($tenant);

        $metrics = [
            'cpus' => [$allocated['cpus'], (float) $quotas['cpus']],
            'memory_mb' => [$allocated['memory_mb'], (int) $quotas['memory_mb']],
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
     * Highest utilization (percent) among the quotas that are enforced as hard limits.
     */
    public static function peakUtilization(array $snapshot): float
    {
        $peak = 0.0;
        foreach (['cpus', 'memory_mb', 'containers', 'resources', 'storage_gb'] as $key) {
            $peak = max($peak, (float) ($snapshot[$key]['percent'] ?? 0));
        }

        return $peak;
    }
}
