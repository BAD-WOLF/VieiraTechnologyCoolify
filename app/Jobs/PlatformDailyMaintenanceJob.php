<?php

namespace App\Jobs;

use App\Actions\Platform\ProvisionTenant;
use App\Models\PlatformInvoice;
use App\Models\PlatformTenant;
use App\Services\Platform\InterBilling;
use App\Services\Platform\StripeBilling;
use App\Services\Platform\UsageAggregator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily reconciliation (wiki 8: "Daily reconciliation job"):
 *  - bills the usage of ended periods that were not attached to a renewal invoice (pending items
 *    go to the next Stripe invoice),
 *  - re-syncs tenant networks so nodes added to a region serve existing tenants,
 *  - prunes raw usage events past the retention window.
 */
class PlatformDailyMaintenanceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 1800;

    public function handle(): void
    {
        if (! isPlatform()) {
            return;
        }
        PlatformTenant::query()->whereNotNull('plan')->whereNotNull('provisioned_at')->chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                try {
                    $this->reconcile($tenant);
                    ProvisionTenant::dispatch($tenant->team_id);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
        UsageAggregator::reconcileDay(now()->subDay());
        UsageAggregator::pruneRawEvents();
    }

    private function reconcile(PlatformTenant $tenant): void
    {
        // With Banco Inter the usage overage is part of the renewal invoice (InterBilling::renew).
        if (InterBilling::enabled() || $tenant->billing_provider === InterBilling::PROVIDER) {
            return;
        }
        $periodEnd = $tenant->current_period_start;
        if (is_null($periodEnd) || $periodEnd->isFuture()) {
            return;
        }
        $periodStart = $tenant->billing_interval === 'yearly' ? $periodEnd->copy()->subYear() : $periodEnd->copy()->subMonth();
        $exists = PlatformInvoice::query()
            ->where('team_id', $tenant->team_id)
            ->where('type', 'usage')
            ->where('period_end', '>=', $periodEnd->copy()->subDay())
            ->exists();
        if ($exists || $periodEnd->lt(now()->subDays(40)) || $periodStart->lt($tenant->created_at)) {
            return;
        }
        StripeBilling::billUsage($tenant, $periodStart, $periodEnd);
    }
}
