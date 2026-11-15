<div class="flex flex-col w-full gap-6 pb-10">
    @if ($inter && $pendingInvoice)
        @include('livewire.platform.partials.invoice-payment', ['invoice' => $pendingInvoice, 'qr' => $qr, 'cancellable' => $pendingInvoice->type === 'subscription'])
    @endif
    @if ($inter && $editingProfile)
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

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        @foreach ($plans as $plan)
            <div wire:key="plan-{{ $plan['id'] }}" @class([
                'flex flex-col gap-4 p-6 border rounded-sm dark:border-coolgray-300 border-neutral-200',
                'ring-2 ring-warning' => $currentPlan === $plan['id'],
            ])>
                <div>
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

                <ul class="flex flex-col gap-1 text-sm dark:text-neutral-300">
                    <li><strong class="dark:text-white">{{ rtrim(rtrim(number_format($plan['quotas']['cpus'], 2, '.', ''), '0'), '.') }}</strong> vCPU</li>
                    <li><strong class="dark:text-white">{{ round($plan['quotas']['memory_mb'] / 1024, 1) }} GB</strong> {{ __('RAM') }}</li>
                    <li><strong class="dark:text-white">{{ $plan['quotas']['storage_gb'] }} GB</strong> {{ __('storage') }}</li>
                    <li><strong class="dark:text-white">{{ $plan['quotas']['resources'] }}</strong> {{ __('applications, databases or services') }}</li>
                    <li><strong class="dark:text-white">{{ $plan['quotas']['egress_gb'] }} GB</strong> {{ __('egress included') }}</li>
                    <li>
                        <strong class="dark:text-white">{{ $plan['quotas']['deployments_per_day'] > 0 ? $plan['quotas']['deployments_per_day'] : __('Unlimited') }}</strong>
                        {{ __('deployments per day') }}
                    </li>
                </ul>

                <ul class="flex flex-col gap-1 text-sm">
                    @foreach ([
                        'persistent_volumes' => __('Persistent volumes'),
                        'scheduled_backups' => __('Scheduled backups'),
                        'log_export' => __('Log export'),
                        'preview_deployments' => __('Preview deployments'),
                        'public_databases' => __('Public databases'),
                        'dedicated_server' => __('Dedicated nodes (VM isolation)'),
                        'sso' => __('SSO (SAML/OIDC)'),
                            'traces' => __('Application traces (OpenTelemetry)'),
                    ] as $feature => $label)
                        <li @class(['flex gap-2', 'dark:text-white' => $plan['features'][$feature] ?? false, 'line-through dark:text-neutral-600 text-neutral-400' => !($plan['features'][$feature] ?? false)])>
                            <span>{{ ($plan['features'][$feature] ?? false) ? '✓' : '✗' }}</span>{{ $label }}
                        </li>
                    @endforeach
                </ul>

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
        {{ __('CPU and memory are hard limits of the plan. Egress and storage above the plan are billed on the next invoice.') }}
        @if ($inter)
            {{ __('Payment with Pix or boleto (Banco Inter). Upgrades are charged proportionally; downgrades apply on the next renewal.') }}
        @endif
    </p>
</div>
