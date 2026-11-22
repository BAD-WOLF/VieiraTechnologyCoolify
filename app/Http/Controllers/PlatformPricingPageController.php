<?php

namespace App\Http\Controllers;

use App\Services\Platform\PlanCatalog;
use Illuminate\Contracts\View\View;

/**
 * Public page with the plans, quotas and prices (wiki 8: "Documentation pública de planos, quotas, TOS").
 */
class PlatformPricingPageController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(isPlatform(), 404);
        $plans = collect(PlanCatalog::public())->map(fn (array $plan, string $key) => [
            'id' => $key,
            'name' => $plan['name'],
            'description' => $plan['description'] ?? '',
            'definition' => $plan,
            'highlighted' => (bool) ($plan['highlighted'] ?? false),
            'contact_sales' => (bool) ($plan['contact_sales'] ?? false),
            'monthly' => PlanCatalog::displayPrice($key, PlanCatalog::INTERVAL_MONTHLY),
            'yearly' => PlanCatalog::displayPrice($key, PlanCatalog::INTERVAL_YEARLY),
        ])->values();

        return view('platform.pricing-page', [
            'plans' => $plans,
            'salesUrl' => config('platform.sales_url') ?: (config('platform.support_email') ? 'mailto:'.config('platform.support_email') : null),
            'termsUrl' => config('platform.branding.terms_url'),
            'privacyUrl' => config('platform.branding.privacy_url'),
            'registrationEnabled' => (bool) instanceSettings()->is_registration_enabled,
        ]);
    }
}
