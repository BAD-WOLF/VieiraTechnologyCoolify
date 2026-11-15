<?php

namespace App\Livewire\Platform;

use App\Services\Platform\PlanCatalog;
use App\Services\Platform\StripeBilling;
use Livewire\Component;

/**
 * Plan selection (Básico, Profissional, Enterprise) with Stripe Checkout.
 */
class Pricing extends Component
{
    public string $interval = PlanCatalog::INTERVAL_MONTHLY;

    public function setInterval(string $interval): void
    {
        $this->interval = $interval === PlanCatalog::INTERVAL_YEARLY ? PlanCatalog::INTERVAL_YEARLY : PlanCatalog::INTERVAL_MONTHLY;
    }

    public function subscribe(string $plan)
    {
        try {
            if (! isPlatformTenant() || ! auth()->user()->isAdmin()) {
                $this->dispatch('error', __('Only team owners and admins can manage the subscription.'));

                return;
            }
            if (! PlanCatalog::exists($plan)) {
                $this->dispatch('error', __('Unknown plan.'));

                return;
            }

            return redirect()->away(StripeBilling::checkoutUrl(currentTeam(), auth()->user(), $plan, $this->interval));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
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

        return view('livewire.platform.pricing', [
            'plans' => $plans,
            'currentPlan' => platformTenant()?->plan,
            'salesUrl' => config('platform.sales_url') ?: (config('platform.support_email') ? 'mailto:'.config('platform.support_email') : null),
        ]);
    }
}
