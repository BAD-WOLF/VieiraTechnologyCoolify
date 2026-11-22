<?php

namespace App\Jobs;

use App\Services\Platform\PlatformBilling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Billing cycle (Efí or manual): renewals, overdue invoices and reconciliation of open charges.
 */
class PlatformBillingCycleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function handle(): void
    {
        if (! isPlatform()) {
            return;
        }
        PlatformBilling::runCycle();
    }
}
