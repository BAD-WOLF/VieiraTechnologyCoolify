<?php

namespace App\Livewire\Platform;

use App\Services\Platform\TenantUsage;
use Livewire\Component;

/**
 * Compact plan/usage card for the dashboard of tenants.
 */
class UsageSummary extends Component
{
    public function render()
    {
        $tenant = platformTenant();

        return view('livewire.platform.usage-summary', [
            'tenant' => $tenant,
            'snapshot' => $tenant ? TenantUsage::snapshot($tenant) : [],
        ]);
    }
}
