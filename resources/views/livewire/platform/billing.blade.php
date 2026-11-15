<div class="flex flex-col gap-6">
    @if (request()->query('success'))
        <x-callout type="success" title="{{ __('Subscription confirmed') }}">
            {{ __('Thank you! Your plan is being activated; this can take a few seconds.') }}
        </x-callout>
    @endif

    @foreach ($openInvoices as $openInvoice)
        @include('livewire.platform.partials.invoice-payment', ['invoice' => $openInvoice, 'qr' => $qrCodes[$openInvoice->uuid] ?? null])
    @endforeach

    <section class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <h2>{{ __('Plan') }}: {{ $tenant->planName() }}</h2>
            <span @class([
                'px-2 py-0.5 text-xs rounded-sm',
                'bg-success/20 text-success' => $tenant->status === 'active',
                'bg-warning/20 text-warning' => in_array($tenant->status, ['limited', 'pending']),
                'bg-error/20 text-error' => in_array($tenant->status, ['suspended', 'hard_suspended', 'cancelled']),
            ])>{{ __(str($tenant->status)->replace('_', ' ')->title()->value()) }}</span>
        </div>
        @if ($price)
            <div class="text-sm dark:text-neutral-400">{{ $price }} / {{ $tenant->billing_interval === 'yearly' ? __('year') : __('month') }}
                · {{ __('Region') }}: {{ $tenant->region?->name ?? '—' }}
                @if ($inter && $tenant->current_period_end)
                    · {{ $tenant->cancel_at_period_end ? __('Ends on :date', ['date' => $tenant->current_period_end->format('d/m/Y')]) : __('Renews on :date', ['date' => $tenant->current_period_end->format('d/m/Y')]) }}
                @endif
            </div>
        @endif
        @if ($inter && $tenant->pending_plan)
            <x-callout type="info" title="{{ __('Scheduled change') }}">
                {{ __('Your plan changes to :plan on the next renewal.', ['plan' => \App\Services\Platform\PlanCatalog::get($tenant->pending_plan)['name'] ?? $tenant->pending_plan]) }}
                @if ($isAdmin)
                    <x-forms.button class="mt-2" wire:click="cancelPendingChange">{{ __('Keep the current plan') }}</x-forms.button>
                @endif
            </x-callout>
        @endif
        @if ($tenant->blockReason())
            <x-callout type="danger" title="{{ __('Action required') }}">{{ $tenant->blockReason() }}</x-callout>
        @endif
        @if ($isAdmin)
            <div class="flex flex-wrap gap-2">
                @if ($inter)
                    <x-forms.button wire:click="$toggle('showPlans')">{{ $showPlans ? __('Hide plans') : __('Change plan') }}</x-forms.button>
                    @if ($tenant->current_period_end && in_array($tenant->status, ['active', 'limited', 'suspended']))
                        @if ($tenant->cancel_at_period_end)
                            <x-forms.button wire:click="resumeSubscription">{{ __('Keep my subscription') }}</x-forms.button>
                        @else
                            <x-forms.button wire:click="cancelSubscription"
                                wire:confirm="{{ __('The plan stays active until :date and is not renewed. After that, deployments are disabled and the containers are stopped a few days later. Cancel the subscription?', ['date' => $tenant->current_period_end->format('d/m/Y')]) }}">
                                {{ __('Cancel subscription') }}
                            </x-forms.button>
                        @endif
                    @endif
                @else
                    <x-forms.button wire:click="portal">{{ __('Manage billing (payment method, invoices, cancel)') }}</x-forms.button>
                @endif
            </div>
        @endif
    </section>

    @if ($inter && $showPlans)
        <livewire:platform.pricing key="billing-plans" />
    @endif

    <section class="flex flex-col gap-3">
        <h3>{{ __('Usage') }}
            <span class="text-xs font-normal dark:text-neutral-400">{{ $periodStart->toDateString() }} → {{ $periodEnd->toDateString() }}</span>
        </h3>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @include('livewire.platform.partials.usage-bar', ['label' => __('CPU (reserved)'), 'item' => $snapshot['cpus'], 'unit' => 'vCPU'])
            @include('livewire.platform.partials.usage-bar', ['label' => __('Memory (reserved)'), 'item' => $snapshot['memory_mb'], 'unit' => 'MB'])
            @include('livewire.platform.partials.usage-bar', ['label' => __('Containers'), 'item' => $snapshot['containers']])
            @include('livewire.platform.partials.usage-bar', ['label' => __('Resources'), 'item' => $snapshot['resources']])
            @include('livewire.platform.partials.usage-bar', ['label' => __('Storage'), 'item' => $snapshot['storage_gb'], 'unit' => 'GB'])
            @include('livewire.platform.partials.usage-bar', ['label' => __('Egress this period'), 'item' => $snapshot['egress_gb'], 'unit' => 'GB'])
            @include('livewire.platform.partials.usage-bar', ['label' => __('Deployments (last 24h)'), 'item' => $snapshot['deployments_per_day']])
            @include('livewire.platform.partials.usage-bar', ['label' => __('Custom domains'), 'item' => $snapshot['custom_domains']])
        </div>
    </section>

    <section class="flex flex-col gap-2">
        <h3>{{ __('Invoice preview (usage so far)') }}</h3>
        <table class="text-sm">
            <tbody>
                @foreach ($preview['lines'] as $line)
                    <tr wire:key="preview-{{ $line['code'] }}" class="border-b dark:border-coolgray-300">
                        <td class="py-1 dark:text-neutral-300">{{ __($line['description']) }}</td>
                        <td class="py-1 text-right dark:text-neutral-400">{{ $line['quantity'] }} {{ $line['unit'] }}</td>
                        <td class="py-1 text-right dark:text-white">{{ formatPlatformMoney($line['amount_cents'], $preview['currency']) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td class="py-1 font-bold dark:text-white" colspan="2">{{ __('Usage charges so far') }}</td>
                    <td class="py-1 font-bold text-right dark:text-white">{{ formatPlatformMoney($preview['amount_cents'], $preview['currency']) }}</td>
                </tr>
                @if ($inter && $nextPrice && ! $tenant->cancel_at_period_end)
                    <tr>
                        <td class="py-1 dark:text-neutral-300" colspan="2">{{ __('Next renewal (plan + usage so far)') }}</td>
                        <td class="py-1 text-right dark:text-white">{{ formatPlatformMoney($nextPrice + $preview['amount_cents'], 'brl') }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
        <div class="text-xs dark:text-neutral-400">
            {{ $inter ? __('Usage above the plan is added to the renewal invoice of the next period.') : __('The plan price is charged by the subscription; usage charges are added to the next invoice.') }}
        </div>
    </section>

    @if ($inter)
        <section class="flex flex-col gap-2">
            @if ($editingProfile && $isAdmin)
                @include('livewire.platform.partials.billing-profile-form', ['cancellable' => $profileComplete])
            @else
                <div class="flex flex-wrap items-center gap-2">
                    <h3>{{ __('Billing details') }}</h3>
                    @if ($isAdmin)
                        <x-forms.button wire:click="$set('editingProfile', true)">{{ __('Edit') }}</x-forms.button>
                    @endif
                </div>
                <div class="text-sm dark:text-neutral-400">
                    @if ($profileComplete)
                        {{ $profile['name'] }} · {{ $profile['email'] }} · {{ $profile['city'] }}/{{ $profile['state'] }}
                    @else
                        {{ __('Fill in the billing details to subscribe.') }}
                    @endif
                </div>
            @endif
        </section>
    @endif

    <section class="flex flex-col gap-2">
        <h3>{{ __('Invoices') }}</h3>
        @forelse ($invoices as $invoice)
            <div wire:key="invoice-{{ $invoice->uuid }}" class="flex flex-wrap items-center justify-between gap-2 p-2 text-sm border rounded-sm dark:border-coolgray-300">
                <span class="font-mono dark:text-white">{{ $invoice->number ?? '—' }}</span>
                <span class="dark:text-neutral-300">{{ $invoice->period_start->format('d/m/Y') }} → {{ $invoice->period_end->format('d/m/Y') }}</span>
                <span class="dark:text-neutral-300">{{ $invoice->provider === 'inter' ? \App\Services\Platform\InterBilling::describe($invoice) : __('Usage') }}</span>
                <span class="dark:text-white">{{ $invoice->formattedAmount() }}</span>
                <span @class([
                    'text-xs uppercase',
                    'text-success' => $invoice->status === 'paid',
                    'text-warning' => $invoice->status === 'open',
                    'dark:text-neutral-400' => ! in_array($invoice->status, ['paid', 'open']),
                ])>{{ __(ucfirst($invoice->status)) }}</span>
                <span class="flex gap-2">
                    @if ($invoice->provider === 'inter' && filled($invoice->provider_reference))
                        <button class="underline dark:text-white" wire:click="downloadBoleto('{{ $invoice->uuid }}')">{{ __('Boleto') }}</button>
                    @endif
                    <button class="underline dark:text-white" wire:click="downloadUsage('{{ $invoice->uuid }}')">{{ __('Usage (CSV)') }}</button>
                    @if ($invoice->stripe_hosted_invoice_url)
                        <a class="underline dark:text-white" href="{{ $invoice->stripe_hosted_invoice_url }}" target="_blank">{{ __('View') }}</a>
                    @endif
                    @if ($isAdmin && $invoice->provider === 'inter' && $invoice->status === 'void' && is_null($invoice->paid_at) && $invoice->type === 'subscription' && data_get($invoice->metadata, 'void_reason') !== 'superseded')
                        <button class="underline dark:text-white" wire:click="reissueInvoice('{{ $invoice->uuid }}')">{{ __('Issue a new charge') }}</button>
                    @endif
                </span>
            </div>
        @empty
            <div class="text-sm dark:text-neutral-400">{{ __('No invoices yet.') }}</div>
        @endforelse
    </section>
</div>
