<section class="flex flex-col gap-2">
    <h3>{{ __('Webhooks') }}</h3>
    <div class="text-sm dark:text-neutral-400">
        {{ __('We send a signed POST (JSON) to your endpoint for every event you choose. Check the header X-Hostrema-Signature with the secret of the webhook.') }}
    </div>
    @if ($newSecret)
        <x-callout type="warning" title="{{ __('Copy the secret now') }}">
            <div>{{ __('It is shown only once.') }}</div>
            <code class="break-all">{{ $newSecret }}</code>
        </x-callout>
    @endif
    @forelse ($webhooks as $webhook)
        <div wire:key="webhook-{{ $webhook->uuid }}" class="flex flex-col gap-1 p-3 border rounded-sm dark:border-coolgray-300">
            <div class="flex flex-wrap items-center gap-2">
                <strong class="break-all dark:text-white">{{ $webhook->url }}</strong>
                <span class="text-xs">{{ $webhook->enabled ? __('Enabled') : __('Disabled') }}</span>
                @if ($webhook->all_teams)
                    <span class="text-xs">{{ __('All teams') }}</span>
                @endif
            </div>
            <div class="text-xs dark:text-neutral-400">
                {{ __('Events') }}: {{ implode(', ', (array) $webhook->events) }}
                @if ($webhook->description)
                    · {{ $webhook->description }}
                @endif
                @if ($webhook->last_delivery_at)
                    · {{ __('Last delivery :date (HTTP :status)', ['date' => $webhook->last_delivery_at->format('d/m/Y H:i'), 'status' => $webhook->last_response_status ?? '—']) }}
                @endif
                @if ($webhook->disabled_reason)
                    · {{ $webhook->disabled_reason }}
                @endif
            </div>
            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    <x-forms.button wire:click="test('{{ $webhook->uuid }}')">{{ __('Send test event') }}</x-forms.button>
                    <x-forms.button wire:click="toggle('{{ $webhook->uuid }}')">{{ $webhook->enabled ? __('Disable') : __('Enable') }}</x-forms.button>
                    <x-forms.button wire:click="rotate('{{ $webhook->uuid }}')" wire:confirm="{{ __('Generate a new secret? The current one stops being valid.') }}">{{ __('New secret') }}</x-forms.button>
                    <x-forms.button wire:click="delete('{{ $webhook->uuid }}')" wire:confirm="{{ __('Delete this webhook?') }}">{{ __('Delete') }}</x-forms.button>
                </div>
            @endif
            @if ($webhook->deliveries->isNotEmpty())
                <table class="mt-1 text-xs">
                    <tbody>
                        @foreach ($webhook->deliveries as $delivery)
                            <tr wire:key="delivery-{{ $delivery->uuid }}">
                                <td class="pr-2">{{ $delivery->created_at->format('d/m H:i:s') }}</td>
                                <td class="pr-2">{{ $delivery->event_type }}</td>
                                <td class="pr-2">{{ __($delivery->status) }}{{ $delivery->response_status ? ' · HTTP '.$delivery->response_status : '' }}</td>
                                <td class="pr-2 break-all">{{ $delivery->error }}</td>
                                <td>
                                    @if ($canManage && $delivery->status === 'failed')
                                        <button class="underline" wire:click="resend('{{ $delivery->uuid }}')">{{ __('Resend') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @empty
        <div class="text-sm dark:text-neutral-400">{{ __('No webhooks yet.') }}</div>
    @endforelse
    @if ($canManage)
        <form wire:submit="create" class="flex flex-col gap-2 pt-2">
            <x-forms.input id="url" label="{{ __('Endpoint URL (HTTPS)') }}" placeholder="https://example.com/hooks/hostrema" required />
            <x-forms.input id="description" label="{{ __('Description (optional)') }}" />
            <div class="flex flex-wrap gap-4">
                @foreach ($groupOptions as $value => $label)
                    <label class="flex items-center gap-1 text-sm"><input type="checkbox" wire:model="groups" value="{{ $value }}" class="dark:bg-coolgray-100" /> {{ $label }}</label>
                @endforeach
            </div>
            @error('groups')
                <div class="text-xs text-error">{{ $message }}</div>
            @enderror
            @if ($isOperator)
                <label class="flex items-center gap-1 text-sm"><input type="checkbox" wire:model="allTeams" class="dark:bg-coolgray-100" /> {{ __('Receive the events of every team (operator)') }}</label>
            @endif
            <div>
                <x-forms.button type="submit">{{ __('Add webhook') }}</x-forms.button>
            </div>
        </form>
    @endif
</section>
