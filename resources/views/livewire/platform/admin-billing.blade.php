<div class="flex flex-col gap-6">
    @if (! $enabled)
        <x-callout type="warning" title="{{ __('Efí Bank is not the billing provider') }}">
            {{ __('Choose Efí Bank as the payment provider in Settings > Billing.') }}
        </x-callout>
    @elseif ($manual)
        <x-callout type="info" title="{{ __('Manual payments') }}">
            {{ __('Invoices are issued without the bank. Confirm each payment with "Mark as paid" below. To charge by Pix and boleto automatically, fill in the Efí credentials below and click "Save and connect".') }}
        </x-callout>
    @endif
    @if ($result)
        <x-callout :type="$resultOk ? 'success' : 'danger'" title="{{ $resultOk ? __('Done') : __('Error') }}">{{ $result }}</x-callout>
    @endif

    <form wire:submit="save" class="flex flex-col gap-3">
        <div>
            <h3>{{ __('Efí Bank') }}
                <span @class(['px-2 py-0.5 ml-2 text-xs rounded-sm', 'bg-success/20 text-success' => $configured, 'bg-warning/20 text-warning' => ! $configured])>
                    {{ $configured ? __('Boleto ready') : __('Not configured') }}
                </span>
                <span @class(['px-2 py-0.5 ml-1 text-xs rounded-sm', 'bg-success/20 text-success' => $pixReady, 'bg-warning/20 text-warning' => ! $pixReady])>
                    {{ $pixReady ? __('Pix ready') : __('Pix not configured') }}
                </span>
            </h3>
            <div class="text-xs dark:text-neutral-400">
                {{ __('In the Efí account (Efí Pro, without CNPJ, or Efí Empresas): API > Aplicações > Criar aplicação, enable "API Cobranças" and "API Pix" (scopes cobv.write, cobv.read, webhook.write and webhook.read), copy the Client ID and Client Secret of the environment and, in API > Meus certificados, create and download the certificate (.p12) of the same environment.') }}
            </div>
        </div>
        <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
            <x-forms.select id="environment" wire:model.live="environment" label="{{ __('Environment') }}"
                helper="{{ __('Each environment keeps its own Client ID, Client Secret, certificate and Pix key.') }} {{ $inUse ? __('This is the environment in use.') : __('Saving makes this environment the one in use.') }}">
                <option value="production">{{ __('Production') }}</option>
                <option value="sandbox">{{ __('Sandbox (tests)') }}</option>
            </x-forms.select>
            <x-forms.input id="pixKey" label="{{ __('Pix key of the account') }}"
                helper="{{ __('With a Pix key and the certificate, invoices are Pix charges with a due date. Customers can also choose a boleto with a Pix QR code. Nobody is asked for an address.') }}" />
            <x-forms.input id="clientId" label="Client ID" />
            <x-forms.input id="clientSecret" type="password" label="Client Secret"
                placeholder="{{ $hasSecret ? __('Stored - leave empty to keep') : '' }}" />
        </div>
        <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
            <div class="flex flex-col gap-1">
                <label class="text-sm dark:text-white">{{ __('Certificate of the application (.p12 or .pem)') }}
                    <span class="text-xs dark:text-neutral-400">{{ $hasCertificate ? __('Stored - send another one to replace it') : __('Needed for Pix') }}</span>
                </label>
                <input type="file" wire:model="certificateFile" accept=".p12,.pfx,.pem" class="text-xs" />
                @error('certificateFile') <span class="text-xs text-error">{{ $message }}</span> @enderror
            </div>
            <x-forms.input id="certificatePassword" type="password" label="{{ __('Password of the certificate (usually empty)') }}" />
        </div>

        <div class="flex flex-wrap gap-2">
            <x-forms.button type="submit" isHighlighted>{{ __('Save and connect') }}</x-forms.button>
            <x-forms.button type="button" wire:click="connect">{{ __('Test connection and register webhook') }}</x-forms.button>
            <x-forms.button type="button" wire:click="runCycle">{{ __('Run billing cycle now') }}</x-forms.button>
        </div>
        <div class="text-xs dark:text-neutral-400">
            {{ __('Pix webhook registered') }}: {{ $webhookAt ? \Carbon\Carbon::parse($webhookAt)->format('d/m/Y H:i') : '—' }}
            · {{ __('Last notification received') }}: {{ $lastWebhookAt ? \Carbon\Carbon::parse($lastWebhookAt)->format('d/m/Y H:i') : '—' }}
        </div>
    </form>

    <section class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <h3>{{ __('Invoices') }}</h3>
            <div class="w-64"><x-forms.input id="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Number or team name...') }}" /></div>
        </div>
        @forelse ($invoices as $invoice)
            <div wire:key="admin-invoice-{{ $invoice->uuid }}" class="flex flex-wrap items-center justify-between gap-2 p-2 text-sm border rounded-sm dark:border-coolgray-300">
                <span class="font-mono dark:text-white">{{ $invoice->number }}</span>
                <span class="dark:text-neutral-300">{{ $invoice->team?->name }} (#{{ $invoice->team_id }})</span>
                <span class="dark:text-neutral-300">{{ \App\Services\Platform\PlatformBilling::describe($invoice) }}</span>
                <span class="dark:text-white">{{ $invoice->formattedAmount() }}</span>
                <span class="text-xs dark:text-neutral-400">{{ __('Due on :date', ['date' => $invoice->due_date?->format('d/m/Y')]) }}</span>
                <span class="text-xs uppercase dark:text-neutral-400">{{ __(ucfirst($invoice->status)) }} · {{ $invoice->provider_status ?? '—' }}</span>
                <span class="flex flex-wrap gap-2">
                    @if ($invoice->status === 'open')
                        <button class="underline dark:text-white" wire:click="syncInvoice('{{ $invoice->uuid }}')">{{ __('Check at the bank') }}</button>
                        <button class="underline dark:text-white" wire:click="markPaid('{{ $invoice->uuid }}')" wire:confirm="{{ __('Mark this invoice as paid (received outside the Efí charge)?') }}">{{ __('Mark as paid') }}</button>
                        <button class="underline dark:text-white" wire:click="cancelInvoice('{{ $invoice->uuid }}')" wire:confirm="{{ __('Cancel this invoice at the bank?') }}">{{ __('Cancel') }}</button>
                        @if ($sandbox && $invoice->provider_reference)
                            <button class="underline dark:text-white" wire:click="simulatePayment('{{ $invoice->uuid }}')">{{ __('Simulate payment') }}</button>
                        @endif
                    @endif
                    @if (in_array($invoice->status, ['open', 'void']) && is_null($invoice->paid_at))
                        <button class="underline dark:text-white" wire:click="reissue('{{ $invoice->uuid }}')">{{ __('Issue a new charge') }}</button>
                    @endif
                </span>
                @if (data_get($invoice->metadata, 'issue_error'))
                    <span class="w-full text-xs text-error">{{ data_get($invoice->metadata, 'issue_error') }}</span>
                @endif
            </div>
        @empty
            <div class="text-sm dark:text-neutral-400">{{ __('No invoices yet.') }}</div>
        @endforelse
    </section>
</div>
