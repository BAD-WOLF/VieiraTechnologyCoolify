{{-- Open invoice (Efí or manual): Pix QR code and copy and paste code, or boleto line, its Pix code and PDF. Expects $invoice and $qr. --}}
@php
    $details = (array) $invoice->payment_details;
    $isPix = \App\Services\Platform\PlatformBilling::isPix($invoice);
    $isBoleto = \App\Services\Platform\PlatformBilling::isBoleto($invoice);
    $paymentMethods = \App\Services\Platform\BillingProfile::methods();
    $waitingBank = filled($invoice->provider_reference) && ! \App\Services\Platform\PlatformBilling::isManualInvoice($invoice);
@endphp
<div wire:key="pay-{{ $invoice->uuid }}" class="flex flex-col gap-4 p-4 border rounded-sm border-warning/60"
    @if ($waitingBank) wire:poll.5s="checkPayment('{{ $invoice->uuid }}')" @endif>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div>
            <h3>{{ __('Payment pending') }}: {{ $invoice->formattedAmount() }}</h3>
            <div class="text-sm dark:text-neutral-400">
                {{ $invoice->number }} · {{ \App\Services\Platform\PlatformBilling::describe($invoice) }}
                · {{ __('Due on :date', ['date' => $invoice->due_date?->format('d/m/Y')]) }}
            </div>
        </div>
        <div class="flex items-center gap-3">
            @if ($invoice->due_date?->isPast())
                <span class="px-2 py-0.5 text-xs rounded-sm bg-error/20 text-error">{{ __('Overdue') }}</span>
            @endif
            <a href="{{ route('dashboard') }}" class="text-sm underline dark:text-white">← {{ __('Back to home') }}</a>
        </div>
    </div>

    @if (filled(data_get($invoice->metadata, 'issue_error')) && blank($invoice->provider_reference))
        <x-callout type="danger" title="{{ __('The bank did not accept the charge') }}">
            {{ data_get($invoice->metadata, 'issue_error') }}
            <div class="pt-2">{{ __('Check the billing details and issue the charge again.') }}</div>
        </x-callout>
    @elseif (\App\Services\Platform\PlatformBilling::isManualInvoice($invoice))
        <div class="flex flex-col gap-2 text-sm">
            <span class="font-bold dark:text-white">{{ __('How to pay') }}</span>
            <div class="whitespace-pre-line dark:text-neutral-300">{{ $details['instructions'] ?? '' }}</div>
            <span class="text-xs dark:text-neutral-400">{{ __('Your plan is activated as soon as we confirm the payment.') }}</span>
        </div>
    @elseif (blank($details['pix_copia_e_cola'] ?? null) && blank($details['linha_digitavel'] ?? null))
        <div class="text-sm dark:text-neutral-300">{{ __('Generating the charge at the bank... this takes a few seconds.') }}</div>
    @else
        <div class="flex flex-col gap-4 lg:flex-row">
            @if ($qr)
                <div class="p-2 bg-white rounded-sm w-fit">{!! $qr !!}</div>
            @endif
            <div class="flex flex-col flex-1 gap-3 text-sm" x-data="{ copied: null, copy(value, key) { navigator.clipboard.writeText(value); this.copied = key; setTimeout(() => this.copied = null, 2000) } }">
                @if (filled($details['pix_copia_e_cola'] ?? null))
                    <div class="flex flex-col gap-1">
                        <span class="font-bold dark:text-white">{{ __('Pix (copy and paste)') }}</span>
                        <div class="flex gap-2">
                            <input class="w-full input" readonly value="{{ $details['pix_copia_e_cola'] }}" />
                            <x-forms.button type="button" x-on:click="copy(@js($details['pix_copia_e_cola']), 'pix')">
                                <span x-text="copied === 'pix' ? @js(__('Copied')) : @js(__('Copy'))"></span>
                            </x-forms.button>
                        </div>
                        <span class="text-xs dark:text-neutral-400">{{ __('Pix payments are confirmed in a few seconds. This page updates by itself.') }}</span>
                    </div>
                @endif
                @if ($isBoleto && filled($details['linha_digitavel'] ?? null))
                    <div class="flex flex-col gap-1">
                        <span class="font-bold dark:text-white">{{ __('Boleto') }}</span>
                        <div class="flex gap-2">
                            <input class="w-full input" readonly value="{{ $details['linha_digitavel'] }}" />
                            <x-forms.button type="button" x-on:click="copy(@js($details['linha_digitavel']), 'boleto')">
                                <span x-text="copied === 'boleto' ? @js(__('Copied')) : @js(__('Copy'))"></span>
                            </x-forms.button>
                        </div>
                        <span class="text-xs dark:text-neutral-400">{{ __('Boletos are confirmed in up to 3 business days; the Pix code of the boleto, in seconds.') }}</span>
                    </div>
                @endif
                <div class="flex flex-wrap gap-2">
                    @if ($isBoleto && str_starts_with((string) ($details['boleto_url'] ?? ''), 'https://'))
                        <a class="button" href="{{ $details['boleto_url'] }}" target="_blank" rel="noopener">{{ __('Download boleto (PDF)') }}</a>
                    @endif
                    <x-forms.button type="button" wire:click="refreshInvoice('{{ $invoice->uuid }}')">{{ __('I already paid, check again') }}</x-forms.button>
                </div>
                @if ($isPix && in_array(\App\Services\Platform\BillingProfile::BOLETO, $paymentMethods, true))
                    <button type="button" class="text-xs text-left underline w-fit dark:text-neutral-300" wire:click="payWith('boleto')">{{ __('Prefer a boleto?') }}</button>
                @elseif ($isBoleto && in_array(\App\Services\Platform\BillingProfile::PIX, $paymentMethods, true))
                    <button type="button" class="text-xs text-left underline w-fit dark:text-neutral-300" wire:click="payWith('pix')">{{ __('Pay with Pix instead') }}</button>
                @endif
            </div>
        </div>
    @endif
    <div class="flex flex-wrap gap-2">
        @if (in_array($invoice->provider_status, \App\Services\Platform\PlatformBilling::VOID_STATUSES, true) || filled(data_get($invoice->metadata, 'issue_error')) || $invoice->due_date?->isPast())
            <x-forms.button type="button" wire:click="reissueInvoice('{{ $invoice->uuid }}')">{{ __('Issue a new charge') }}</x-forms.button>
        @endif
        @if ($cancellable ?? false)
            <x-forms.button type="button" wire:click="cancelCheckout('{{ $invoice->uuid }}')">{{ __('Choose another plan') }}</x-forms.button>
        @endif
        @if (\App\Services\Platform\PlatformBilling::allowsSimulatedPayments())
            <x-forms.button type="button" wire:click="simulatePayment('{{ $invoice->uuid }}')">{{ __('Simulate payment (tests)') }}</x-forms.button>
        @endif
    </div>
</div>
