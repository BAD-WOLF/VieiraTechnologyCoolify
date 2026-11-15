{{-- Payer details required by the bank to register the boleto. Expects $states and an action name. --}}
<form wire:submit="{{ $action ?? 'saveProfile' }}" class="flex flex-col gap-3 p-4 border rounded-sm dark:border-coolgray-300 border-neutral-200">
    <div>
        <h3>{{ __('Billing details') }}</h3>
        <div class="text-xs dark:text-neutral-400">{{ __('Used on the invoices (boleto + Pix). Your data is stored encrypted.') }}</div>
    </div>
    <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
        <x-forms.input id="profile.name" label="{{ __('Name or company name') }}" required />
        <x-forms.input id="profile.document" label="{{ __('CPF or CNPJ') }}" required />
        <x-forms.input id="profile.email" type="email" label="{{ __('Billing e-mail') }}" required />
        <x-forms.input id="profile.phone" label="{{ __('Phone (optional)') }}" />
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
    <div class="flex gap-2">
        <x-forms.button type="submit" isHighlighted>{{ $submitLabel ?? __('Save') }}</x-forms.button>
        @if ($cancellable ?? false)
            <x-forms.button type="button" wire:click="$set('editingProfile', false)">{{ __('Cancel') }}</x-forms.button>
        @endif
    </div>
</form>
