<?php

namespace App\Jobs;

use App\Models\PlatformTenant;
use App\Services\Platform\TenantLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Applies quota warnings, soft throttling and billing suspensions to every tenant.
 */
class PlatformEnforcementJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 600;

    public function handle(): void
    {
        if (! isPlatform()) {
            return;
        }
        PlatformTenant::query()
            ->whereNotNull('plan')
            ->where('status', '!=', PlatformTenant::STATUS_PENDING)
            ->with('team')
            ->chunkById(100, function ($tenants) {
                foreach ($tenants as $tenant) {
                    try {
                        TenantLifecycle::evaluate($tenant);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
    }
}
