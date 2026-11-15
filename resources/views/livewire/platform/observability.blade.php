<section class="flex flex-col gap-2">
    <h3>{{ __('Application traces (OpenTelemetry)') }}</h3>
    @if (! $enabled)
        <div class="text-sm dark:text-neutral-400">{{ __('Available on the Profissional and Enterprise plans.') }}</div>
    @elseif (! $endpoint)
        <div class="text-sm dark:text-neutral-400">{{ __('The OTLP endpoint of your region is not configured yet.') }}</div>
    @else
        <div class="text-sm dark:text-neutral-400">
            {{ __('Send traces from your applications with any OpenTelemetry SDK. They are stored separately for your team and can be queried from your own Grafana (Tempo data source).') }}
        </div>
        @if ($newToken)
            <x-callout type="warning" title="{{ __('Copy the token now') }}">
                {{ __('It is shown only once. Generating a new token revokes this one.') }}
            </x-callout>
        @endif
        <pre class="p-3 overflow-x-auto text-xs rounded-sm dark:bg-coolgray-100 bg-neutral-100 dark:text-white">OTEL_EXPORTER_OTLP_ENDPOINT={{ $endpoint }}
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer {{ $newToken ?? ($hasToken ? '<'.__('your token').'>' : '<'.__('generate a token').'>') }}
OTEL_SERVICE_NAME=&lt;{{ __('application name') }}&gt;</pre>
        <div class="text-xs dark:text-neutral-400">
            {{ __('Grafana: Tempo data source with URL :url and the header "Authorization: Bearer <token>".', ['url' => $endpoint.'/tempo']) }}
            @if ($hasToken && $createdAt)
                · {{ __('Token created on :date', ['date' => $createdAt->format('d/m/Y H:i')]) }}
            @endif
        </div>
        @if ($isAdmin)
            <div class="flex gap-2">
                @if ($hasToken)
                    <x-forms.button wire:click="generate" wire:confirm="{{ __('Generate a new token? Applications using the current one stop sending traces.') }}">{{ __('Generate a new token') }}</x-forms.button>
                    <x-forms.button wire:click="revoke" wire:confirm="{{ __('Revoke the token?') }}">{{ __('Revoke') }}</x-forms.button>
                @else
                    <x-forms.button wire:click="generate">{{ __('Generate token') }}</x-forms.button>
                @endif
            </div>
        @endif
    @endif
</section>
