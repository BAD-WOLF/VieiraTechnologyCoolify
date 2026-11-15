<?php

namespace App\Services\Platform;

use App\Models\AggregatedUsageHourly;
use App\Models\UsageEvent;
use Carbon\CarbonInterface;

/**
 * Hourly rollups of the raw usage events (wiki 8: aggregated_usage_hourly).
 * Counters (CPU, memory, egress) are summed; storage is a gauge: the per-run totals are averaged.
 */
class UsageAggregator
{
    public static function aggregateHour(CarbonInterface $hour): int
    {
        $start = $hour->copy()->startOfHour();
        $end = $start->copy()->addHour();
        $events = UsageEvent::query()
            ->where('recorded_at', '>=', $start)
            ->where('recorded_at', '<', $end)
            ->get(['team_id', 'metric', 'value', 'recorded_at']);

        $rows = [];
        foreach ($events->groupBy(fn ($event) => $event->team_id.'|'.$event->metric) as $key => $group) {
            [$teamId, $metric] = explode('|', $key, 2);
            if ($metric === UsageEvent::METRIC_STORAGE_BYTES) {
                $perRun = $group->groupBy(fn ($event) => $event->recorded_at->toDateTimeString())->map->sum('value');
                $value = $perRun->avg() ?? 0;
                $samples = $perRun->count();
            } else {
                $value = $group->sum('value');
                $samples = $group->count();
            }
            $rows[] = [
                'team_id' => (int) $teamId,
                'metric' => $metric,
                'period_start' => $start->toDateTimeString(),
                'period_end' => $end->toDateTimeString(),
                'value' => (float) $value,
                'samples' => $samples,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];
        }
        if (count($rows) > 0) {
            AggregatedUsageHourly::query()->upsert($rows, ['team_id', 'metric', 'period_start'], ['value', 'samples', 'period_end', 'updated_at']);
        }

        return count($rows);
    }

    public static function pruneRawEvents(): int
    {
        $days = max(7, (int) config('platform.metering.raw_retention_days', 90));

        return UsageEvent::query()->where('recorded_at', '<', now()->subDays($days))->delete();
    }
}
