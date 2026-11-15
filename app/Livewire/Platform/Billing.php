<?php

namespace App\Livewire\Platform;

use App\Models\PlatformInvoice;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PricingEngine;
use App\Services\Platform\TenantUsage;
use Livewire\Component;

/**
 * "Plan & usage": quotas vs. consumption, invoice preview, invoices and the Stripe billing portal.
 */
class Billing extends Component
{
    public function mount()
    {
        if (! isPlatformTenant()) {
            return redirect()->route('dashboard');
        }
    }

    public function portal()
    {
        try {
            if (! auth()->user()->isAdmin()) {
                $this->dispatch('error', __('Only team owners and admins can manage the subscription.'));

                return;
            }
            $session = getStripeCustomerPortalSession(currentTeam());
            if (is_null($session)) {
                $this->dispatch('error', __('No billing account found. Choose a plan first.'));

                return;
            }

            return redirect()->away($session->url);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $tenant = platformTenant();
        [$periodStart, $periodEnd] = TenantUsage::currentPeriod($tenant);

        return view('livewire.platform.billing', [
            'tenant' => $tenant,
            'snapshot' => TenantUsage::snapshot($tenant),
            'preview' => PricingEngine::calculate($tenant, $periodStart, now()),
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'price' => $tenant->plan ? PlanCatalog::displayPrice($tenant->plan, $tenant->billing_interval ?? PlanCatalog::INTERVAL_MONTHLY) : null,
            'invoices' => PlatformInvoice::query()->where('team_id', $tenant->team_id)->latest('period_start')->limit(24)->get(),
            'isAdmin' => auth()->user()->isAdmin(),
        ]);
    }
}
