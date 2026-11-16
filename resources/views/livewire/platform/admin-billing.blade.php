<div class="flex flex-col gap-6">
    @if (! $enabled)
        <x-callout type="warning" title="{{ __('Banco Inter is not the billing provider') }}">
            {{ __('Choose Banco Inter as the payment provider in Settings > Billing.') }}
        </x-callout>
    @elseif ($manual)
        <x-callout type="info" title="{{ __('Manual payments') }}">
            {{ __('Invoices are issued without the bank. Confirm each payment with "Mark as paid" below. To charge by boleto and Pix automatically, choose Banco Inter in Settings > Billing and fill in the credentials.') }}
        </x-callout>
    @endif
    @if ($result)
        <x-callout :type="$resultOk ? 'success' : 'danger'" title="{{ $resultOk ? __('Done') : __('Error') }}">{{ $result }}</x-callout>
    @endif

    <form wire:submit="save" class="flex flex-col gap-3">
        <div>
            <h3>{{ __('Banco Inter') }}
                <span @class(['px-2 py-0.5 ml-2 text-xs rounded-sm', 'bg-success/20 text-success' => $configured, 'bg-warning/20 text-warning' => ! $configured])>
                    {{ $configured ? __('Configured') : __('Not configured') }}
                </span>
            </h3>
            <div class="text-xs dark:text-neutral-400">
                {{ __('Inter Empresas > Soluções para sua empresa > Nova integração: enable "API Pix" (cobv.write, cobv.read, webhook.write, webhook.read, pix.read) and "API Cobrança (Boleto e Pix)" (boleto-cobranca.read, boleto-cobranca.write), then paste the Client ID, Client Secret, certificate (.crt) and key (.key) of the downloaded zip here.') }}
            </div>
        </div>
        <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
            <x-forms.select id="environment" label="{{ __('Environment') }}">
                <option value="production">{{ __('Production') }}</option>
                <option value="sandbox">{{ __('Sandbox (tests)') }}</option>
            </x-forms.select>
            <x-forms.input id="contaCorrente" label="{{ __('Checking account (only if the application has more than one)') }}" />
            <x-forms.input id="pixKey" label="{{ __('Pix key of the account') }}"
                helper="{{ __('With a Pix key, invoices are Pix charges and customers only give name, CPF/CNPJ and e-mail; the address is asked only from those who choose boleto. Without it, every invoice is a boleto with Pix and the address is required.') }}" />
            <x-forms.input id="clientId" label="Client ID" />
            <x-forms.input id="clientSecret" type="password" label="Client Secret"
                placeholder="{{ $hasSecret ? __('Stored - leave empty to keep') : '' }}" />
        </div>
        <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
            <div class="flex flex-col gap-1">
                <x-forms.textarea id="certificate" rows="4" label="{{ __('Certificate (.crt)') }}"
                    placeholder="{{ $hasCertificate ? __('Stored - leave empty to keep') : '-----BEGIN CERTIFICATE-----' }}" />
                <input type="file" wire:model="certificateFile" accept=".crt,.pem,.cer" class="text-xs" />
                @error('certificate') <span class="text-xs text-error">{{ $message }}</span> @enderror
            </div>
            <div class="flex flex-col gap-1">
                <x-forms.textarea id="privateKey" rows="4" label="{{ __('Private key (.key)') }}"
                    placeholder="{{ $hasKey ? __('Stored - leave empty to keep') : '-----BEGIN PRIVATE KEY-----' }}" />
                <input type="file" wire:model="privateKeyFile" accept=".key,.pem" class="text-xs" />
                @error('privateKey') <span class="text-xs text-error">{{ $message }}</span> @enderror
            </div>
        </div>

        <div>
            <h3>{{ __('Plan prices (BRL)') }}</h3>
            <div class="text-xs dark:text-neutral-400">{{ __('Empty = not sold by self-service (e.g. Enterprise, contact sales).') }}</div>
        </div>
        <div class="grid grid-cols-1 gap-2 lg:grid-cols-3">
            @foreach ($plans as $key => $plan)
                <div wire:key="price-{{ $key }}" class="flex flex-col gap-1 p-2 border rounded-sm dark:border-coolgray-300">
                    <span class="font-bold dark:text-white">{{ $plan['name'] }}</span>
                    <x-forms.input id="prices.{{ $key }}.monthly" label="{{ __('Monthly') }}" placeholder="49,90" />
                    <x-forms.input id="prices.{{ $key }}.yearly" label="{{ __('Yearly') }}" placeholder="499,00" />
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap gap-2">
            <x-forms.button type="submit" isHighlighted>{{ __('Save and connect') }}</x-forms.button>
            <x-forms.button type="button" wire:click="connect">{{ __('Test connection and register webhook') }}</x-forms.button>
            <x-forms.button type="button" wire:click="runCycle">{{ __('Run billing cycle now') }}</x-forms.button>
        </div>
        <div class="text-xs dark:text-neutral-400">
            {{ __('Webhook registered') }}: {{ $webhookAt ? \Carbon\Carbon::parse($webhookAt)->format('d/m/Y H:i') : '—' }}
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
                <span class="dark:text-neutral-300">{{ \App\Services\Platform\InterBilling::describe($invoice) }}</span>
                <span class="dark:text-white">{{ $invoice->formattedAmount() }}</span>
                <span class="text-xs dark:text-neutral-400">{{ __('Due on :date', ['date' => $invoice->due_date?->format('d/m/Y')]) }}</span>
                <span class="text-xs uppercase dark:text-neutral-400">{{ __(ucfirst($invoice->status)) }} · {{ $invoice->provider_status ?? '—' }}</span>
                <span class="flex flex-wrap gap-2">
                    @if ($invoice->status === 'open')
                        <button class="underline dark:text-white" wire:click="syncInvoice('{{ $invoice->uuid }}')">{{ __('Check at the bank') }}</button>
                        <button class="underline dark:text-white" wire:click="markPaid('{{ $invoice->uuid }}')" wire:confirm="{{ __('Mark this invoice as paid (received outside the Inter charge)?') }}">{{ __('Mark as paid') }}</button>
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
