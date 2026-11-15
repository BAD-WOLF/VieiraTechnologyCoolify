<?php

namespace App\Services\Platform;

use App\Models\AggregatedUsageHourly;
use App\Models\PlatformInvoice;
use Carbon\CarbonImmutable;

/**
 * CSV with an invoice's lines and the hourly usage behind it (wiki 8, "Dispute flow").
 */
class InvoiceUsageExport
{
    public static function write(PlatformInvoice $invoice): void
    {
        $out = fopen('php://output', 'w');
        [$from, $to] = self::window($invoice);
        fputcsv($out, ['invoice', $invoice->number ?? $invoice->uuid, 'status', $invoice->status]);
        fputcsv($out, ['period', $invoice->period_start->toIso8601String(), $invoice->period_end->toIso8601String()]);
        fputcsv($out, ['usage_window', $from->toIso8601String(), $to->toIso8601String()]);
        fputcsv($out, []);
        fputcsv($out, ['code', 'description', 'quantity', 'unit', 'amount_brl']);
        foreach ((array) $invoice->lines as $line) {
            fputcsv($out, [
                $line['code'] ?? '',
                __((string) ($line['description'] ?? '')),
                $line['quantity'] ?? '',
                $line['unit'] ?? '',
                number_format(((int) ($line['amount_cents'] ?? 0)) / 100, 2, '.', ''),
            ]);
        }
        fputcsv($out, ['total', '', '', '', number_format($invoice->amount_cents / 100, 2, '.', '')]);
        fputcsv($out, []);
        fputcsv($out, ['hour_start', 'metric', 'value', 'samples']);
        AggregatedUsageHourly::query()
            ->where('team_id', $invoice->team_id)
            ->where('period_start', '>=', $from)
            ->where('period_start', '<', $to)
            ->orderBy('period_start')
            ->orderBy('metric')
            ->chunk(2000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [$row->period_start->toIso8601String(), $row->metric, $row->value, $row->samples]);
                }
            });
        fclose($out);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(PlatformInvoice $invoice): array
    {
        $from = data_get($invoice->metadata, 'usage_from');
        $to = data_get($invoice->metadata, 'usage_to');
        if ($from && $to) {
            return [CarbonImmutable::parse($from), CarbonImmutable::parse($to)];
        }

        return [CarbonImmutable::instance($invoice->period_start), CarbonImmutable::instance($invoice->period_end)];
    }
}
