<?php

namespace App\Jobs;

use App\Actions\Platform\ProvisionTenant;
use App\Models\PlatformInvoice;
use App\Models\PlatformTenant;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\StripeBilling;
use App\Services\Platform\UsageAggregator;
use App\Services\Platform\Webhooks;
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

    /**
     * The unique lock expires on its own: a job lost from the queue (horizon:clear, a Redis
     * restart) must not stop this job for good.
     */
    public int $uniqueFor = 7200;

    public function handle(): void
    {
        if (! isPlatform()) {
            return;
        }
        PlatformTenant::query()->whereNotNull('plan')->whereNotNull('provisioned_at')->chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                try {
                    $this->reconcile($tenant);
                } catch (\Throwable $e) {
                    report($e);
                }
                try {
                    ProvisionTenant::dispatch($tenant->team_id);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
        UsageAggregator::reconcileDay(now()->subDay());
        UsageAggregator::pruneRawEvents();
        Webhooks::prune();
    }

    private function reconcile(PlatformTenant $tenant): void
    {
        // With Efí or manual billing the usage overage is part of the renewal invoice (PlatformBilling::renew).
        if (PlatformBilling::enabled() || $tenant->billing_provider === PlatformBilling::PROVIDER) {
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
