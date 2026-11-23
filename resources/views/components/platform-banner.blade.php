@php
    $platformTenantForBanner = isPlatformTenant() ? platformTenant() : null;
@endphp
@if (isPlatform() && \App\Services\Platform\Impersonation::active())
    <form method="POST" action="{{ route('platform.impersonation.stop') }}" class="flex flex-wrap items-center gap-2 px-4 py-2 mb-4 text-sm border rounded-sm border-warning dark:text-white">
        @csrf
        {{ __('You are accessing the dashboard as :user (:team). Everything you do is recorded in the audit log with your name.', ['user' => auth()->user()?->email, 'team' => currentTeam()?->name]) }}
        <button type="submit" class="underline">{{ __('Back to the operator console') }}</button>
    </form>
@endif
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
            <a class="underline" href="{{ route('platform.privacy', ['pedido' => 'review']) }}">{{ __('Ask for a review of this decision') }}</a>
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
