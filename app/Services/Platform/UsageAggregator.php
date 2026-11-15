<?php

namespace App\Services\Platform;

use App\Models\AggregatedUsageHourly;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
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

    /**
     * Daily reconciliation (wiki 8): raw counters vs. hourly rollups with a 0.1% tolerance.
     * Mismatching days are aggregated again and recorded in the audit log.
     *
     * @return array<int, array{team_id: int, metric: string, raw: float, aggregated: float}>
     */
    public static function reconcileDay(CarbonInterface $day, float $tolerance = 0.001): array
    {
        $start = CarbonImmutable::instance($day)->startOfDay();
        $end = $start->addDay();
        $counters = [UsageEvent::METRIC_CPU_SECONDS, UsageEvent::METRIC_MEMORY_BYTE_SECONDS, UsageEvent::METRIC_EGRESS_BYTES];
        $raw = UsageEvent::query()->whereIn('metric', $counters)
            ->where('recorded_at', '>=', $start)->where('recorded_at', '<', $end)
            ->selectRaw('team_id, metric, sum(value) as total')->groupBy('team_id', 'metric')->get();
        $aggregated = AggregatedUsageHourly::query()->whereIn('metric', $counters)
            ->where('period_start', '>=', $start)->where('period_start', '<', $end)
            ->selectRaw('team_id, metric, sum(value) as total')->groupBy('team_id', 'metric')->get()
            ->keyBy(fn ($row) => $row->team_id.'|'.$row->metric);

        $mismatches = [];
        foreach ($raw as $row) {
            $rawTotal = (float) $row->total;
            $rolled = (float) ($aggregated->get($row->team_id.'|'.$row->metric)?->total ?? 0);
            if (abs($rawTotal - $rolled) > max(1e-6, abs($rawTotal) * $tolerance)) {
                $mismatches[] = ['team_id' => (int) $row->team_id, 'metric' => $row->metric, 'raw' => $rawTotal, 'aggregated' => $rolled];
            }
        }
        if ($mismatches !== []) {
            for ($hour = $start; $hour->lt($end); $hour = $hour->addHour()) {
                self::aggregateHour($hour);
            }
            audit_log('platform.reconciliation.mismatch', null, ['day' => $start->toDateString(), 'mismatches' => array_slice($mismatches, 0, 50)], 0, 'system');
        }

        return $mismatches;
    }

    public static function pruneRawEvents(): int
    {
        $days = max(7, (int) config('platform.metering.raw_retention_days', 90));

        return UsageEvent::query()->where('recorded_at', '<', now()->subDays($days))->delete();
    }
}
