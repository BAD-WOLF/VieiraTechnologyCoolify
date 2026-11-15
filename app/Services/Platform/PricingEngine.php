<?php

namespace App\Services\Platform;

use App\Models\AggregatedUsageHourly;
use App\Models\PlatformTenant;
use App\Models\UsageEvent;
use Carbon\CarbonInterface;

/**
 * Turns hourly rollups into invoice lines (wiki: "Pricing Engine consome rollups e aplica rulesets").
 *
 * The plan price itself is charged by the Stripe subscription. CPU and memory are hard limits
 * enforced by cgroups, so they are reported for transparency but never billed as overage; egress
 * and storage above the plan are billed per GB.
 */
class PricingEngine
{
    private const HOURS_PER_MONTH = 730;

    private const BYTES_PER_GB = 1_000_000_000;

    /**
     * @return array{currency: string, amount_cents: int, lines: array<int, array{code: string, description: string, quantity: float, unit: string, unit_amount_cents: int, amount_cents: int}>}
     */
    public static function calculate(PlatformTenant $tenant, CarbonInterface $from, CarbonInterface $to): array
    {
        $quotas = $tenant->quotas();
        $currency = (string) config('platform.currency', 'brl');
        $rates = (array) config('platform.overage', []);
        $months = max(1, (int) round($from->diffInDays($to) / 30));

        $usage = AggregatedUsageHourly::query()
            ->where('team_id', $tenant->team_id)
            ->where('period_start', '>=', $from)
            ->where('period_start', '<', $to)
            ->get(['metric', 'value', 'period_start']);

        $cpuHours = $usage->where('metric', UsageEvent::METRIC_CPU_SECONDS)->sum('value') / 3600;
        $memoryGbHours = $usage->where('metric', UsageEvent::METRIC_MEMORY_BYTE_SECONDS)->sum('value') / self::BYTES_PER_GB / 3600;
        $egressGb = $usage->where('metric', UsageEvent::METRIC_EGRESS_BYTES)->sum('value') / self::BYTES_PER_GB;
        $storageGbHoursAboveQuota = $usage->where('metric', UsageEvent::METRIC_STORAGE_BYTES)
            ->sum(fn ($row) => max(0, ($row->value / self::BYTES_PER_GB) - (int) $quotas['storage_gb']));

        $lines = [
            self::line('cpu_hours', 'vCPU-hours used (included in plan)', round($cpuHours, 2), 'vCPU-h', 0),
            self::line('memory_gb_hours', 'Memory GB-hours used (included in plan)', round($memoryGbHours, 2), 'GB-h', 0),
        ];

        $includedEgress = (int) $quotas['egress_gb'] * $months;
        $egressOverage = max(0, (int) ceil($egressGb - $includedEgress));
        $lines[] = self::line(
            'egress_overage',
            sprintf('Egress above the %d GB included (%.2f GB used)', $includedEgress, $egressGb),
            $egressOverage,
            'GB',
            (int) ($rates['egress_gb_cents'] ?? 0),
        );

        $storageGbMonths = round($storageGbHoursAboveQuota / self::HOURS_PER_MONTH, 3);
        $lines[] = self::line(
            'storage_overage',
            sprintf('Storage above the %d GB included', (int) $quotas['storage_gb']),
            $storageGbMonths,
            'GB-month',
            (int) ($rates['storage_gb_month_cents'] ?? 0),
        );

        return [
            'currency' => $currency,
            'amount_cents' => array_sum(array_column($lines, 'amount_cents')),
            'lines' => $lines,
        ];
    }

    /**
     * @return array{code: string, description: string, quantity: float, unit: string, unit_amount_cents: int, amount_cents: int}
     */
    private static function line(string $code, string $description, float|int $quantity, string $unit, int $unitAmountCents): array
    {
        return [
            'code' => $code,
            'description' => $description,
            'quantity' => (float) $quantity,
            'unit' => $unit,
            'unit_amount_cents' => $unitAmountCents,
            'amount_cents' => (int) round($quantity * $unitAmountCents),
        ];
    }
}
