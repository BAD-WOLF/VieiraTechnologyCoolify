<?php

namespace App\Livewire\Platform;

use App\Livewire\Platform\Concerns\ManagesInterInvoices;
use App\Models\PlatformInvoice;
use App\Services\Platform\BillingProfile;
use App\Services\Platform\InterBilling;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PricingEngine;
use App\Services\Platform\TenantUsage;
use Livewire\Component;

/**
 * "Plan & usage": quotas vs. consumption, invoices to pay (Pix / boleto), billing details,
 * plan changes and cancellation.
 */
class Billing extends Component
{
    use ManagesInterInvoices;

    public bool $showPlans = false;

    public function mount()
    {
        if (! isPlatformTenant()) {
            return redirect()->route('dashboard');
        }
        if (InterBilling::enabled()) {
            $this->loadProfile(platformTenant());
        }
    }

    public function portal()
    {
        try {
            if (! $this->canManageBilling()) {
                return null;
            }
            $session = getStripeCustomerPortalSession(currentTeam());
            if (is_null($session)) {
                $this->dispatch('error', __('No billing account found. Choose a plan first.'));

                return null;
            }

            return redirect()->away($session->url);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function cancelSubscription(): void
    {
        if ($this->canManageBilling()) {
            InterBilling::cancelAtPeriodEnd(platformTenant(), true);
            $this->dispatch('success', __('The subscription ends on :date.', ['date' => platformTenant()->current_period_end?->format('d/m/Y')]));
        }
    }

    public function resumeSubscription(): void
    {
        if ($this->canManageBilling()) {
            InterBilling::cancelAtPeriodEnd(platformTenant(), false);
            $this->dispatch('success', __('The subscription will be renewed.'));
        }
    }

    public function cancelPendingChange(): void
    {
        if ($this->canManageBilling()) {
            platformTenant()->update(['pending_plan' => null, 'pending_interval' => null]);
        }
    }

    public function render()
    {
        $tenant = platformTenant();
        [$periodStart, $periodEnd] = TenantUsage::currentPeriod($tenant);
        $inter = InterBilling::enabled();
        $open = $inter ? PlatformInvoice::query()
            ->where('team_id', $tenant->team_id)
            ->where('provider', InterBilling::PROVIDER)
            ->where('status', PlatformInvoice::STATUS_OPEN)
            ->latest('id')
            ->get() : collect();
        $nextPlan = $tenant->pending_plan ?: $tenant->plan;
        $nextInterval = $tenant->pending_interval ?: ($tenant->billing_interval ?? PlanCatalog::INTERVAL_MONTHLY);

        return view('livewire.platform.billing', [
            'tenant' => $tenant,
            'inter' => $inter,
            'snapshot' => TenantUsage::snapshot($tenant),
            'preview' => PricingEngine::calculate($tenant, $periodStart, now()),
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'price' => $tenant->plan ? PlanCatalog::displayPrice($tenant->plan, $tenant->billing_interval ?? PlanCatalog::INTERVAL_MONTHLY) : null,
            'nextPrice' => $nextPlan ? InterBilling::priceCents($nextPlan, $nextInterval) : null,
            'openInvoices' => $open,
            'qrCodes' => $open->mapWithKeys(fn (PlatformInvoice $invoice) => [$invoice->uuid => self::pixQrSvg(data_get($invoice->payment_details, 'pix_copia_e_cola'))]),
            'invoices' => PlatformInvoice::query()->where('team_id', $tenant->team_id)->latest('id')->limit(36)->get(),
            'isAdmin' => auth()->user()->isAdmin(),
            'states' => BillingProfile::STATES,
            'profileComplete' => BillingProfile::isComplete($tenant),
        ]);
    }
}
