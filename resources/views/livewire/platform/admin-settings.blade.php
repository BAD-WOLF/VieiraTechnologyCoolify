<div class="flex flex-col gap-4">
    <div class="flex flex-wrap gap-2">
        @foreach ($groups as $key => $label)
            <x-forms.button wire:click="selectGroup('{{ $key }}')" :isHighlighted="$group === $key">{{ __($label) }}</x-forms.button>
        @endforeach
    </div>
    <div class="text-xs dark:text-neutral-400">
        {{ __('Every value starts with the platform default and can be changed here; nothing needs to be edited in the server environment. A changed value shows "custom" and can go back to the default.') }}
    </div>
    @unless ($canEdit)
        <x-callout type="info" title="{{ __('Read only') }}">{{ __('Only the owner of the root team can change the settings.') }}</x-callout>
    @endunless

    @if ($checklist)
        <section class="flex flex-col gap-1 p-3 border rounded-sm dark:border-coolgray-300">
            <h3>{{ __('Ready to sell?') }}</h3>
            @foreach ($checklist as $item)
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span @class(['px-2 py-0.5 text-xs rounded-sm', 'bg-success/20 text-success' => $item['ok'], 'bg-warning/20 text-warning' => ! $item['ok']])>{{ $item['ok'] ? __('OK') : __('Pending') }}</span>
                    <span class="dark:text-white">{{ $item['label'] }}</span>
                    @unless ($item['ok'])
                        <span class="text-xs dark:text-neutral-400">{{ $item['hint'] }}</span>
                        @if ($item['url'])
                            <a class="text-xs underline dark:text-white" href="{{ $item['url'] }}">{{ __('Open page') }}</a>
                        @endif
                    @endunless
                </div>
            @endforeach
        </section>
    @endif

    @if ($group === 'plans')
        <form wire:submit="save" class="flex flex-col gap-3">
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="text-left">{{ __('Setting') }}</th>
                            @foreach ($plans as $plan => $name)
                                <th class="text-left">{{ $name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($planRows as $rowKey => $row)
                            @if ($loop->first || $planRows[array_keys($planRows)[$loop->index - 1]]['section'] !== $row['section'])
                                <tr>
                                    <td colspan="{{ count($plans) + 1 }}" class="pt-3 font-bold dark:text-white">
                                        {{ ['plan' => __('Plan'), 'quotas' => __('Quotas'), 'features' => __('Features')][$row['section']] ?? '' }}
                                    </td>
                                </tr>
                            @endif
                            <tr wire:key="plan-row-{{ $rowKey }}">
                                <td class="pr-2">{{ __($row['label']) }}</td>
                                @foreach ($plans as $plan => $name)
                                    @php($key = $row['cells'][$plan] ?? null)
                                    <td class="pr-2">
                                        @if ($key)
                                            @if ($row['type'] === 'bool')
                                                <input type="checkbox" class="checkbox" wire:model="form.{{ $key }}" @disabled(! $canEdit) />
                                            @else
                                                <input class="w-full min-w-24 input" wire:model="form.{{ $key }}" @disabled(! $canEdit)
                                                    @if (in_array($row['type'], ['int', 'decimal'], true)) type="number" step="any" @endif
                                                    @if ($row['type'] === 'money') placeholder="{{ __('no price') }}" @endif />
                                            @endif
                                            @isset($overridden[\App\Livewire\Platform\AdminSettings::path($key)])
                                                <button type="button" class="text-xs underline" title="{{ __('Back to the default') }}" wire:click="resetSetting('{{ $key }}')" @disabled(! $canEdit)>{{ __('custom') }}</button>
                                            @endisset
                                            @error('form.'.$key)
                                                <div class="text-xs text-error">{{ $message }}</div>
                                            @enderror
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="text-xs dark:text-neutral-400">{{ __('Prices in the platform currency (e.g. 49,90). An empty price means the plan is only sold by the sales team. New limits apply to every customer on the plan within a few minutes.') }}</div>
            @if ($canEdit)
                <div><x-forms.button type="submit">{{ __('Save') }}</x-forms.button></div>
            @endif
        </form>
    @else
        @if ($fields)
            <form wire:submit="save" class="flex flex-col gap-3">
                @foreach ($fields as $path => $field)
                    @php($key = \App\Livewire\Platform\AdminSettings::key($path))
                    <div wire:key="setting-{{ $key }}" class="flex flex-col gap-1">
                        @if ($field['type'] === 'bool')
                            <x-forms.checkbox id="form.{{ $key }}" label="{{ __($field['label']) }}" :disabled="! $canEdit" :helper="isset($field['help']) ? __($field['help']) : null" />
                        @elseif ($field['type'] === 'select')
                            <x-forms.select id="form.{{ $key }}" label="{{ __($field['label']) }}" :disabled="! $canEdit" :helper="isset($field['help']) ? __($field['help']) : null">
                                @foreach ($field['options'] as $value => $optionLabel)
                                    <option value="{{ $value }}">{{ __($optionLabel) }}</option>
                                @endforeach
                            </x-forms.select>
                        @elseif ($field['type'] === 'text')
                            <x-forms.textarea id="form.{{ $key }}" label="{{ __($field['label']) }}" rows="3" :disabled="! $canEdit" :helper="isset($field['help']) ? __($field['help']) : null" />
                        @else
                            <x-forms.input id="form.{{ $key }}" label="{{ __($field['label']) }}" :disabled="! $canEdit" :helper="isset($field['help']) ? __($field['help']) : null"
                                type="{{ in_array($field['type'], ['int', 'decimal'], true) ? 'number' : 'text' }}" step="any" />
                        @endif
                        <div class="flex flex-wrap gap-2 text-xs dark:text-neutral-400">
                            @isset($overridden[$path])
                                <span class="text-warning">{{ __('custom') }}</span>
                                @if ($canEdit)
                                    <button type="button" class="underline" wire:click="resetSetting('{{ $key }}')">{{ __('Back to the default') }}</button>
                                @endif
                            @else
                                <span>{{ __('default') }}</span>
                            @endisset
                        </div>
                    </div>
                @endforeach
                @if ($canEdit)
                    <div><x-forms.button type="submit">{{ __('Save') }}</x-forms.button></div>
                @endif
            </form>
        @endif

        @foreach ($secretFields as $path => $field)
            @php($key = \App\Livewire\Platform\AdminSettings::key($path))
            <section wire:key="secret-{{ $key }}" class="flex flex-col gap-2 p-3 border rounded-sm dark:border-coolgray-300">
                <div class="flex flex-wrap items-center gap-2">
                    <strong class="dark:text-white">{{ __($field['label']) }}</strong>
                    <span @class(['px-2 py-0.5 text-xs rounded-sm', 'bg-success/20 text-success' => filled(config($path)), 'bg-warning/20 text-warning' => blank(config($path))])>
                        {{ filled(config($path)) ? __('Configured') : __('Not configured') }}
                    </span>
                </div>
                @if ($canEdit && $revealed === $key && filled(config($path)))
                    <code class="break-all">{{ config($path) }}</code>
                @endif
                @if ($canEdit)
                    <div class="flex flex-wrap items-end gap-2">
                        <x-forms.input id="secrets.{{ $key }}" type="password" label="{{ __('New value') }}" placeholder="{{ filled(config($path)) ? __('Stored - leave empty to keep') : '' }}" />
                        <x-forms.button wire:click="saveSecret('{{ $key }}')">{{ __('Save') }}</x-forms.button>
                        @if (filled(config($path)))
                            <x-forms.button wire:click="reveal('{{ $key }}')">{{ $revealed === $key ? __('Hide') : __('Show') }}</x-forms.button>
                        @endif
                        @if ($field['generate'] ?? false)
                            <x-forms.button wire:click="generateSecret('{{ $key }}')" wire:confirm="{{ __('Generate a new token? Whatever uses the current one stops working until it is updated.') }}">{{ __('Generate new') }}</x-forms.button>
                        @endif
                    </div>
                @endif
            </section>
        @endforeach
    @endif
</div>
