<div>
    <x-slot:title>
        {{ isPlatform() ? __('Plan & usage') : 'Subscription' }} | {{ isPlatform() ? config('platform.name') : 'Coolify' }}
    </x-slot>
    @if (isPlatform())
        <h1>{{ __('Plan & usage') }}</h1>
        <div class="subtitle">{{ __('Your plan, quotas, consumption and invoices.') }}</div>
        <livewire:platform.billing />
    @else
        <h1>Subscription</h1>
        <div class="subtitle">Here you can see and manage your subscription.</div>
        <livewire:subscription.actions />
    @endif
</div>
