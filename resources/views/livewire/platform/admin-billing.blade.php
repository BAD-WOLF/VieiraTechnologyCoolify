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

    @php
        $tested = $check && ! $check['stale'];
        $badge = function (string $method, bool $ready) use ($tested, $check): array {
            $status = $tested ? $check[$method]['status'] : null;

            return match (true) {
                $status === 'ok' => [__('Connected'), 'bg-success/20 text-success'],
                in_array($status, ['failed', 'warning'], true) => [__('Error'), 'bg-error/20 text-error'],
                ! $ready || $status === 'missing' => [__('Not configured'), 'bg-warning/20 text-warning'],
                default => [__('Not tested'), 'bg-neutral-500/20 dark:text-neutral-300'],
            };
        };
        [$boletoBadge, $boletoClass] = $badge('boleto', $configured);
        [$pixBadge, $pixClass] = $badge('pix', $pixReady);
    @endphp
    <form wire:submit="save" class="flex flex-col gap-3">
        <div>
            <h3>{{ __('Efí Bank') }}
                <span class="px-2 py-0.5 ml-2 text-xs rounded-sm {{ $boletoClass }}">{{ __('Boleto') }}: {{ $boletoBadge }}</span>
                <span class="px-2 py-0.5 ml-1 text-xs rounded-sm {{ $pixClass }}">Pix: {{ $pixBadge }}</span>
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
                @if ($certificate)
                    <span @class(['text-xs', 'text-error' => $certificate['expired'] || $certificate['not_yet_valid'], 'dark:text-neutral-400' => ! $certificate['expired'] && ! $certificate['not_yet_valid']])>
                        @if ($certificate['expired'])
                            {{ __('The certificate of :env expired on :date.', ['env' => $environmentLabel, 'date' => $certificate['valid_to']->format('d/m/Y')]) }}
                        @else
                            {{ __('Certificate issued to :subject, valid until :date.', ['subject' => $certificate['subject'], 'date' => $certificate['valid_to']->format('d/m/Y')]) }}
                        @endif
                        <span class="font-mono" title="SHA-256 {{ $certificate['fingerprint'] }}">SHA-256 {{ \Illuminate\Support\Str::limit($certificate['fingerprint'], 23, '…') }}</span>
                    </span>
                @endif
                @if ($sameCertificate)
                    <span class="text-xs text-warning">{{ __('The same certificate is saved in Produção and in Homologação, but each environment of Efí issues certificates of its own: send the one of each environment.') }}</span>
                @endif
            </div>
            <x-forms.input id="certificatePassword" type="password" label="{{ __('Password of the certificate (usually empty)') }}" />
        </div>

        <div class="flex flex-wrap gap-2">
            <x-forms.button type="submit" isHighlighted>{{ __('Save and connect') }}</x-forms.button>
            <x-forms.button type="button" wire:click="connect">{{ __('Test connection') }}</x-forms.button>
            <x-forms.button type="button" wire:click="runCycle">{{ __('Run billing cycle now') }}</x-forms.button>
        </div>

        @if ($check)
            @php
                $level = $check['stale'] ? 'stale' : $check['level'];
                $frame = match ($level) {
                    'ok' => 'border-success/50',
                    'warning' => 'border-warning/60',
                    'failed' => 'border-error/60',
                    default => 'border-neutral-400/40',
                };
                $heading = match ($level) {
                    'ok' => __('Efí connected (:env)', ['env' => $environmentLabel]),
                    'warning' => __('Efí connected in part (:env)', ['env' => $environmentLabel]),
                    'failed' => __('Efí not connected (:env)', ['env' => $environmentLabel]),
                    default => __('Result of the last test (:env)', ['env' => $environmentLabel]),
                };
                $marks = ['ok' => ['✓', 'text-success'], 'failed' => ['✕', 'text-error'], 'warning' => ['!', 'text-warning'], 'missing' => ['○', 'dark:text-neutral-400'], 'skipped' => ['i', 'text-blue-500 dark:text-blue-400']];
            @endphp
            <div wire:key="efi-check-{{ $check['environment'] }}" class="flex flex-col gap-3 p-3 border rounded-sm {{ $frame }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-bold dark:text-white">{{ $heading }}</span>
                    <span class="text-xs dark:text-neutral-400">{{ __('Last test: :time', ['time' => \Carbon\Carbon::parse($check['checked_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i')]) }}</span>
                </div>
                @if ($check['stale'])
                    <div class="text-xs text-warning">{{ __('The credentials, the certificate or the Pix key changed after this test: click "Save and connect" or "Test connection" to test them.') }}</div>
                @endif
                @foreach (['boleto' => __('Boleto'), 'pix' => 'Pix', 'webhook' => __('Payment notices (webhook)')] as $method => $label)
                    @php([$mark, $markClass] = $marks[$check[$method]['status']] ?? ['?', ''])
                    <div class="flex gap-3" wire:key="efi-check-{{ $method }}">
                        <span class="w-5 font-bold text-center {{ $markClass }}">{{ $mark }}</span>
                        <div class="flex flex-col gap-1 text-sm">
                            <span><span class="font-bold dark:text-white">{{ $label }}:</span> <span class="dark:text-neutral-200">{{ $check[$method]['title'] }}</span></span>
                            @if ($check[$method]['fix'])
                                <span class="dark:text-neutral-300"><span class="font-bold">{{ __('How to fix') }}:</span> {{ $check[$method]['fix'] }}</span>
                            @endif
                            @if ($check[$method]['detail'])
                                <span class="text-xs break-all dark:text-neutral-400">{{ $check[$method]['detail'] }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
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
