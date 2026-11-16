@php
    $platformTenantForBanner = isPlatformTenant() ? platformTenant() : null;
@endphp
@if ($platformTenantForBanner && ! $platformTenantForBanner->hasPlan() && ! request()->routeIs('subscription.*'))
    <div class="flex flex-wrap items-center gap-2 px-4 py-2 mb-4 text-sm border rounded-sm border-warning dark:text-white">
        {{ __('Your plan is not active yet. Choose a plan and pay the first invoice to start deploying.') }}
        <a class="underline" href="{{ route('subscription.index') }}">{{ __('Plans and payment') }}</a>
    </div>
@elseif ($platformTenantForBanner && $platformTenantForBanner->hasPlan())
    @if ($platformTenantForBanner->blockReason())
        <div class="px-4 py-2 mb-4 text-sm border rounded-sm border-error dark:text-white">
            {{ $platformTenantForBanner->blockReason() }}
            <a class="underline" href="{{ route('subscription.show') }}">{{ __('Plan & usage') }}</a>
        </div>
    @elseif ($platformTenantForBanner->past_due_since)
        <div class="px-4 py-2 mb-4 text-sm border rounded-sm border-warning dark:text-white">
            {{ __('Your last payment failed. Update your payment method to avoid interruptions.') }}
            <a class="underline" href="{{ route('subscription.show') }}">{{ __('Plan & usage') }}</a>
        </div>
    @elseif ($platformTenantForBanner->quota_warning_notified_at && $platformTenantForBanner->quota_warning_notified_at->gte(now()->subDays(31)))
        <div class="px-4 py-2 mb-4 text-sm border rounded-sm border-warning dark:text-white">
            {{ __('You are close to your plan limits.') }}
            <a class="underline" href="{{ route('subscription.show') }}">{{ __('Plan & usage') }}</a>
        </div>
    @endif
@endif
