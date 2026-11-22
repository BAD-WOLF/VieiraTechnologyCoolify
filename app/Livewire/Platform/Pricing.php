<?php

namespace App\Livewire\Platform;

use App\Livewire\Platform\Concerns\ManagesInvoices;
use App\Models\PlatformInvoice;
use App\Services\Platform\BillingProfile;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\StripeBilling;
use Livewire\Component;

/**
 * Plan selection. Efí (or manual billing): the invoice of the first period is paid with Pix or
 * boleto right here; Stripe: redirects to Stripe Checkout.
 */
class Pricing extends Component
{
    use ManagesInvoices;

    public string $interval = PlanCatalog::INTERVAL_MONTHLY;

    public ?string $selectedPlan = null;

    public function mount(): void
    {
        $tenant = platformTenant();
        if ($tenant && PlatformBilling::enabled()) {
            $this->loadProfile($tenant);
            $this->editingProfile = false;
            if ($tenant->billing_interval) {
                $this->interval = $tenant->billing_interval;
            }
        }
    }

    public function setInterval(string $interval): void
    {
        $this->interval = $interval === PlanCatalog::INTERVAL_YEARLY ? PlanCatalog::INTERVAL_YEARLY : PlanCatalog::INTERVAL_MONTHLY;
    }

    public function subscribe(string $plan)
    {
        try {
            if (! $this->canManageBilling()) {
                return null;
            }
            if (! PlanCatalog::exists($plan)) {
                $this->dispatch('error', __('Unknown plan.'));

                return null;
            }
            if (! PlanCatalog::isSelfServe($plan, $this->interval)) {
                $this->dispatch('error', __('This plan is not available for self-service checkout.'));

                return null;
            }
            if (! PlatformBilling::enabled()) {
                return redirect()->away(StripeBilling::checkoutUrl(currentTeam(), auth()->user(), $plan, $this->interval));
            }
            if (! PlatformBilling::ready()) {
                $this->dispatch('error', __('Payments are not available yet. Please contact support.'));

                return null;
            }
            $tenant = platformTenant();
            if (! BillingProfile::isComplete($tenant)) {
                $this->selectedPlan = $plan;
                $this->editingProfile = true;

                return null;
            }
            $result = PlatformBilling::subscribe($tenant, $plan, $this->interval);
            $this->selectedPlan = null;
            $this->dispatch('success', $result['message']);
            if (in_array($result['action'], ['scheduled', 'kept', 'upgraded'], true)) {
                return redirect()->route('subscription.show');
            }

            return null;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveProfileAndSubscribe()
    {
        $this->saveProfile();
        if ($this->selectedPlan && ! $this->editingProfile) {
            return $this->subscribe($this->selectedPlan);
        }

        return null;
    }

    public function cancelCheckout(string $uuid): void
    {
        if (! $this->canManageBilling()) {
            return;
        }
        PlatformBilling::cancelInvoice($this->teamInvoice($uuid));
    }

    public function render()
    {
        $tenant = platformTenant();
        $invoicing = PlatformBilling::enabled();
        // Public plans, and the customer's own plan when the owner keeps it hidden.
        $plans = collect(PlanCatalog::all())
            ->filter(fn (array $plan, string $key) => PlanCatalog::isPublic($plan) || $key === $tenant?->plan)
            ->map(function (array $plan, string $key) {
                return [
                    'id' => $key,
                    'name' => $plan['name'],
                    'description' => $plan['description'] ?? '',
                    'definition' => $plan,
                    'highlighted' => (bool) ($plan['highlighted'] ?? false),
                    'contact_sales' => (bool) ($plan['contact_sales'] ?? false),
                    'self_serve' => PlanCatalog::isSelfServe($key, $this->interval),
                    'price' => PlanCatalog::displayPrice($key, $this->interval),
                ];
            })->values();
        $pending = $invoicing && $tenant ? PlatformInvoice::query()
            ->where('team_id', $tenant->team_id)
            ->where('provider', PlatformBilling::PROVIDER)
            ->where('status', PlatformInvoice::STATUS_OPEN)
            ->whereIn('type', [PlatformInvoice::TYPE_SUBSCRIPTION, PlatformInvoice::TYPE_UPGRADE])
            ->latest('id')
            ->first() : null;
        if ($pending) {
            $this->watchedInvoice = $pending->uuid;
        }
        $paid = $pending ? null : $this->paidWatchedInvoice();

        return view('livewire.platform.pricing', [
            'plans' => $plans,
            'currentPlan' => PlatformBilling::enabled() && $tenant && ! PlatformBilling::hasActivePlan($tenant) ? null : $tenant?->plan,
            'pendingPlan' => $tenant?->pending_plan,
            'salesUrl' => config('platform.sales_url') ?: (config('platform.support_email') ? 'mailto:'.config('platform.support_email') : null),
            'invoicing' => $invoicing,
            'pendingInvoice' => $pending,
            'paidInvoice' => $paid,
            'qr' => self::pixQrSvg(data_get($pending?->payment_details, 'pix_copia_e_cola')),
        ]);
    }
}
