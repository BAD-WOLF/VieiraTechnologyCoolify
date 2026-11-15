<div class="flex flex-col gap-6">
    @if (request()->query('success'))
        <x-callout type="success" title="{{ __('Subscription confirmed') }}">
            {{ __('Thank you! Your plan is being activated; this can take a few seconds.') }}
        </x-callout>
    @endif

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
                · {{ __('Region') }}: {{ $tenant->region?->name ?? '—' }}</div>
        @endif
        @if ($tenant->blockReason())
            <x-callout type="danger" title="{{ __('Action required') }}">{{ $tenant->blockReason() }}</x-callout>
        @endif
        <div class="flex flex-wrap gap-2">
            @if ($isAdmin)
                <x-forms.button wire:click="portal">{{ __('Manage billing (payment method, invoices, cancel)') }}</x-forms.button>
                <a class="button" href="{{ route('subscription.index') }}">{{ __('Change plan') }}</a>
            @endif
        </div>
    </section>

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
            </tbody>
        </table>
        <div class="text-xs dark:text-neutral-400">{{ __('The plan price is charged by the subscription; usage charges are added to the next invoice.') }}</div>
    </section>

    <section class="flex flex-col gap-2">
        <h3>{{ __('Usage invoices') }}</h3>
        @forelse ($invoices as $invoice)
            <div wire:key="invoice-{{ $invoice->uuid }}" class="flex flex-wrap items-center justify-between gap-2 p-2 text-sm border rounded-sm dark:border-coolgray-300">
                <span class="dark:text-neutral-300">{{ $invoice->period_start->toDateString() }} → {{ $invoice->period_end->toDateString() }}</span>
                <span class="dark:text-white">{{ $invoice->formattedAmount() }}</span>
                <span class="text-xs uppercase dark:text-neutral-400">{{ __($invoice->status) }}</span>
                @if ($invoice->stripe_hosted_invoice_url)
                    <a class="underline dark:text-white" href="{{ $invoice->stripe_hosted_invoice_url }}" target="_blank">{{ __('View') }}</a>
                @endif
            </div>
        @empty
            <div class="text-sm dark:text-neutral-400">{{ __('No usage invoices yet.') }}</div>
        @endforelse
    </section>
</div>
