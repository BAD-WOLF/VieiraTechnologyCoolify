<div class="flex flex-col w-full gap-6 pb-10">
    @if ($paidInvoice)
        <div class="flex flex-col gap-3 p-4 border rounded-sm border-success/60">
            <h3>{{ __('Payment confirmed') }}</h3>
            <div class="text-sm dark:text-neutral-300">
                {{ __('Invoice :number (:amount) is paid and your :plan plan is active. Thank you!', [
                    'number' => $paidInvoice->number,
                    'amount' => $paidInvoice->formattedAmount(),
                    'plan' => \App\Services\Platform\PlanCatalog::get($paidInvoice->plan)['name'] ?? $paidInvoice->plan,
                ]) }}
            </div>
            <div>
                <a href="{{ route('dashboard') }}" class="button">{{ __('Go to the dashboard') }}</a>
            </div>
        </div>
    @endif
    @if ($invoicing && $pendingInvoice)
        @include('livewire.platform.partials.invoice-payment', ['invoice' => $pendingInvoice, 'qr' => $qr, 'cancellable' => $pendingInvoice->type === 'subscription'])
    @endif
    @if ($invoicing && $editingProfile)
        @include('livewire.platform.partials.billing-profile-form', [
            'action' => 'saveProfileAndSubscribe',
            'submitLabel' => __('Save and continue'),
            'cancellable' => true,
        ])
    @endif
    @if ($pendingPlan)
        <x-callout type="info" title="{{ __('Scheduled change') }}">
            {{ __('Your plan changes to :plan on the next renewal.', ['plan' => \App\Services\Platform\PlanCatalog::get($pendingPlan)['name'] ?? $pendingPlan]) }}
        </x-callout>
    @endif
    <div class="flex justify-center">
        <fieldset class="grid grid-cols-2 p-1 text-xs font-semibold leading-5 text-center rounded-sm dark:text-white gap-x-1 dark:bg-white/5 bg-black/5">
            <legend class="sr-only">{{ __('Payment frequency') }}</legend>
            <button type="button" wire:click="setInterval('monthly')" @class([
                'cursor-pointer rounded-sm px-2.5 py-1',
                'dark:bg-coollabs-100 bg-warning dark:text-white' => $interval === 'monthly',
            ])>{{ __('Monthly') }}</button>
            <button type="button" wire:click="setInterval('yearly')" @class([
                'cursor-pointer rounded-sm px-2.5 py-1',
                'dark:bg-coollabs-100 bg-warning dark:text-white' => $interval === 'yearly',
            ])>{{ __('Yearly') }}</button>
        </fieldset>
    </div>

    <div @class([
        'grid grid-cols-1 gap-6',
        'lg:grid-cols-2' => count($plans) === 2 || count($plans) >= 4,
        'lg:grid-cols-3' => count($plans) === 3,
        'xl:grid-cols-4' => count($plans) >= 4,
    ])>
        @foreach ($plans as $plan)
            <div wire:key="plan-{{ $plan['id'] }}" @class([
                'flex flex-col gap-4 p-6 border rounded-sm dark:border-coolgray-300 border-neutral-200',
                'ring-2 ring-warning' => $currentPlan === $plan['id'] || (! $currentPlan && $plan['highlighted']),
            ])>
                <div>
                    @if ($currentPlan === $plan['id'])
                        <div class="pb-1 text-xs font-bold uppercase text-warning">{{ __('Your plan') }}</div>
                    @elseif ($plan['highlighted'])
                        <div class="pb-1 text-xs font-bold uppercase text-warning">{{ __('Recommended') }}</div>
                    @endif
                    <h3 class="text-2xl font-semibold dark:text-white">{{ $plan['name'] }}</h3>
                    <p class="mt-2 text-sm dark:text-neutral-400">{{ __($plan['description']) }}</p>
                </div>
                <div class="text-3xl font-bold dark:text-white">
                    @if ($plan['price'])
                        {{ $plan['price'] }}
                        <span class="text-sm font-normal dark:text-neutral-400">/ {{ $interval === 'yearly' ? __('year') : __('month') }}</span>
                    @elseif ($plan['contact_sales'])
                        <span class="text-xl">{{ __('Custom pricing') }}</span>
                    @else
                        <span class="text-xl">—</span>
                    @endif
                </div>

                @include('platform.partials.plan-details', ['plan' => $plan['definition']])

                <div class="flex-1"></div>
                @if ($currentPlan === $plan['id'])
                    <x-forms.button disabled class="w-full">{{ __('Current plan') }}</x-forms.button>
                @elseif ($plan['self_serve'])
                    <x-forms.button class="w-full" isHighlighted wire:click="subscribe('{{ $plan['id'] }}')" wire:loading.attr="disabled">
                        {{ $currentPlan ? __('Change to this plan') : __('Subscribe') }}
                    </x-forms.button>
                @elseif ($salesUrl)
                    <a class="w-full button" href="{{ $salesUrl }}" target="_blank">{{ __('Talk to sales') }}</a>
                @else
                    <x-forms.button disabled class="w-full">{{ __('Unavailable') }}</x-forms.button>
                @endif
            </div>
        @endforeach
    </div>
    <p class="text-xs dark:text-neutral-400">
        {{ __('Move the pointer over the info icon of each item to see what it means.') }}
        @if ($invoicing)
            {{ __('Upgrades are charged proportionally; downgrades apply on the next renewal.') }}
        @endif
    </p>
</div>
