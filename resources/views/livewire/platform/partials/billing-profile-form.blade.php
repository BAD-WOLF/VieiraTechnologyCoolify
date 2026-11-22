{{-- Payer details: name, CPF/CNPJ and e-mail (and a phone number for boletos). No address. Expects an action name. --}}
@php
    $paymentMethods = \App\Services\Platform\BillingProfile::methods();
    $chosenMethod = \App\Services\Platform\BillingProfile::method($profile['payment_method'] ?? null);
    $askPhone = \App\Services\Platform\BillingProfile::needsPhone($profile['payment_method'] ?? null);
@endphp
<form wire:submit="{{ $action ?? 'saveProfile' }}" class="flex flex-col gap-3 p-4 border rounded-sm dark:border-coolgray-300 border-neutral-200">
    <div>
        <h3>{{ __('Billing details') }}</h3>
        <div class="text-xs dark:text-neutral-400">{{ __('Used on your invoices. Your data is stored encrypted.') }}</div>
    </div>
    <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
        <x-forms.input id="profile.name" label="{{ __('Name or company name') }}" required />
        <x-forms.input id="profile.document" label="{{ __('CPF or CNPJ') }}" required />
        <x-forms.input id="profile.email" type="email" label="{{ __('Billing e-mail') }}" required />
        <x-forms.input id="profile.phone" label="{{ $askPhone ? __('Phone with area code') : __('Phone (optional)') }}" :required="$askPhone" />
    </div>
    @if (count($paymentMethods) > 1)
        <fieldset class="flex flex-col gap-2">
            <legend class="mb-1 text-sm font-medium">{{ __('How do you want to pay?') }}</legend>
            <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                <label @class(['flex gap-3 p-3 border rounded-sm cursor-pointer dark:border-coolgray-300 border-neutral-200', 'ring-2 ring-warning' => $chosenMethod === 'pix'])>
                    <input type="radio" class="mt-1" wire:model.live="profile.payment_method" value="pix" />
                    <span class="flex flex-col">
                        <span class="font-bold dark:text-white">Pix</span>
                        <span class="text-xs dark:text-neutral-400">{{ __('Confirmed in seconds.') }}</span>
                    </span>
                </label>
                <label @class(['flex gap-3 p-3 border rounded-sm cursor-pointer dark:border-coolgray-300 border-neutral-200', 'ring-2 ring-warning' => $chosenMethod === 'boleto'])>
                    <input type="radio" class="mt-1" wire:model.live="profile.payment_method" value="boleto" />
                    <span class="flex flex-col">
                        <span class="font-bold dark:text-white">{{ __('Boleto') }}</span>
                        <span class="text-xs dark:text-neutral-400">{{ __('The barcode is confirmed in up to 3 business days; the Pix QR code of the boleto, in seconds.') }}</span>
                    </span>
                </label>
            </div>
        </fieldset>
    @endif
    <div class="flex gap-2">
        <x-forms.button type="submit" isHighlighted>{{ $submitLabel ?? __('Save') }}</x-forms.button>
        @if ($cancellable ?? false)
            <x-forms.button type="button" wire:click="$set('editingProfile', false)">{{ __('Cancel') }}</x-forms.button>
        @endif
    </div>
</form>
