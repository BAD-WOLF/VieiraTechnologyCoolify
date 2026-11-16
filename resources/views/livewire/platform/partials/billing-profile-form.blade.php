{{-- Payer details. Pix needs only name and CPF/CNPJ; the bank registers a boleto only with the payer's address. Expects $states and an action name. --}}
@php
    $paymentMethods = \App\Services\Platform\BillingProfile::methods();
    $askAddress = \App\Services\Platform\BillingProfile::needsAddress($profile['payment_method'] ?? null);
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
        <x-forms.input id="profile.phone" label="{{ __('Phone (optional)') }}" />
    </div>
    @if (count($paymentMethods) > 1)
        <fieldset class="flex flex-col gap-2">
            <legend class="mb-1 text-sm font-medium">{{ __('How do you want to pay?') }}</legend>
            <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                <label @class(['flex gap-3 p-3 border rounded-sm cursor-pointer dark:border-coolgray-300 border-neutral-200', 'ring-2 ring-warning' => !$askAddress])>
                    <input type="radio" class="mt-1" wire:model.live="profile.payment_method" value="pix" />
                    <span class="flex flex-col">
                        <span class="font-bold dark:text-white">Pix</span>
                        <span class="text-xs dark:text-neutral-400">{{ __('Confirmed in seconds. No address needed.') }}</span>
                    </span>
                </label>
                <label @class(['flex gap-3 p-3 border rounded-sm cursor-pointer dark:border-coolgray-300 border-neutral-200', 'ring-2 ring-warning' => $askAddress])>
                    <input type="radio" class="mt-1" wire:model.live="profile.payment_method" value="boleto" />
                    <span class="flex flex-col">
                        <span class="font-bold dark:text-white">{{ __('Boleto') }}</span>
                        <span class="text-xs dark:text-neutral-400">{{ __('Confirmed in up to 3 business days. The bank asks for your address.') }}</span>
                    </span>
                </label>
            </div>
        </fieldset>
    @endif
    @if ($askAddress)
        <div class="flex flex-col gap-2">
            <div class="text-xs dark:text-neutral-400">{{ __('The bank only registers a boleto with the payer\'s address.') }}</div>
            <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                <x-forms.input id="profile.zip" label="{{ __('ZIP code (CEP)') }}" required />
                <x-forms.input id="profile.street" label="{{ __('Street') }}" required />
                <x-forms.input id="profile.number" label="{{ __('Number') }}" />
                <x-forms.input id="profile.complement" label="{{ __('Complement') }}" />
                <x-forms.input id="profile.district" label="{{ __('District') }}" />
                <x-forms.input id="profile.city" label="{{ __('City') }}" required />
                <x-forms.select id="profile.state" label="{{ __('State') }}" required>
                    <option value="">—</option>
                    @foreach ($states as $state)
                        <option value="{{ $state }}">{{ $state }}</option>
                    @endforeach
                </x-forms.select>
            </div>
        </div>
    @endif
    <div class="flex gap-2">
        <x-forms.button type="submit" isHighlighted>{{ $submitLabel ?? __('Save') }}</x-forms.button>
        @if ($cancellable ?? false)
            <x-forms.button type="button" wire:click="$set('editingProfile', false)">{{ __('Cancel') }}</x-forms.button>
        @endif
    </div>
</form>
