<?php

namespace App\Livewire\Platform;

use App\Livewire\Platform\Concerns\ManagesInterInvoices;
use App\Models\PlatformInvoice;
use App\Services\Platform\BillingProfile;
use App\Services\Platform\InterBilling;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\StripeBilling;
use Livewire\Component;

/**
 * Plan selection (Básico, Profissional, Enterprise). Banco Inter: the invoice of the first period
 * is paid with Pix or boleto right here; Stripe: redirects to Stripe Checkout.
 */
class Pricing extends Component
{
    use ManagesInterInvoices;

    public string $interval = PlanCatalog::INTERVAL_MONTHLY;

    public ?string $selectedPlan = null;

    public function mount(): void
    {
        $tenant = platformTenant();
        if ($tenant && InterBilling::enabled()) {
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
            if (! InterBilling::enabled()) {
                return redirect()->away(StripeBilling::checkoutUrl(currentTeam(), auth()->user(), $plan, $this->interval));
            }
            if (! InterBilling::ready()) {
                $this->dispatch('error', __('Payments are not available yet. Please contact support.'));

                return null;
            }
            $tenant = platformTenant();
            if (! BillingProfile::isComplete($tenant)) {
                $this->selectedPlan = $plan;
                $this->editingProfile = true;

                return null;
            }
            $result = InterBilling::subscribe($tenant, $plan, $this->interval);
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
        InterBilling::cancelInvoice($this->teamInvoice($uuid));
    }

    public function render()
    {
        $tenant = platformTenant();
        $inter = InterBilling::enabled();
        $plans = collect(PlanCatalog::all())->map(function (array $plan, string $key) {
            return [
                'id' => $key,
                'name' => $plan['name'],
                'description' => $plan['description'] ?? '',
                'quotas' => $plan['quotas'],
                'features' => $plan['features'],
                'contact_sales' => (bool) ($plan['contact_sales'] ?? false),
                'self_serve' => PlanCatalog::isSelfServe($key, $this->interval),
                'price' => PlanCatalog::displayPrice($key, $this->interval),
            ];
        })->values();
        $pending = $inter && $tenant ? PlatformInvoice::query()
            ->where('team_id', $tenant->team_id)
            ->where('provider', InterBilling::PROVIDER)
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
            'currentPlan' => InterBilling::enabled() && $tenant && ! InterBilling::hasActivePlan($tenant) ? null : $tenant?->plan,
            'pendingPlan' => $tenant?->pending_plan,
            'salesUrl' => config('platform.sales_url') ?: (config('platform.support_email') ? 'mailto:'.config('platform.support_email') : null),
            'inter' => $inter,
            'pendingInvoice' => $pending,
            'paidInvoice' => $paid,
            'qr' => self::pixQrSvg(data_get($pending?->payment_details, 'pix_copia_e_cola')),
            'states' => BillingProfile::STATES,
        ]);
    }
}
