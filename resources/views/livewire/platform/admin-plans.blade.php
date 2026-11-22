<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex-1">
            <h3>{{ __('Plans') }}</h3>
            <div class="text-xs dark:text-neutral-400">
                {{ __('Everything a plan includes: prices, limits, capabilities and the items shown on the pricing pages. Hidden plans are not listed nor sold, but you can assign them to customers in the Tenants tab.') }}
            </div>
        </div>
        @if ($canEdit && is_null($editing))
            <x-forms.button wire:click="create">{{ __('New plan') }}</x-forms.button>
        @endif
    </div>

    @if (is_null($editing))
        <div class="flex flex-col gap-3">
            @foreach ($plans as $item)
                <div wire:key="plan-{{ $item->slug }}" class="flex flex-col gap-2 p-4 border rounded-sm dark:border-coolgray-300 border-neutral-200">
                    <div class="flex flex-wrap items-center gap-2">
                        <strong class="text-lg dark:text-white">{{ $item->name }}</strong>
                        <code class="text-xs">{{ $item->slug }}</code>
                        <span @class(['px-2 py-0.5 text-xs rounded-sm', 'bg-success/20 text-success' => $item->is_public, 'bg-neutral-500/20' => ! $item->is_public])>
                            {{ $item->is_public ? __('Public') : __('Hidden') }}
                        </span>
                        @if ($item->is_highlighted)
                            <span class="px-2 py-0.5 text-xs rounded-sm bg-warning/20 text-warning">{{ __('Recommended') }}</span>
                        @endif
                        @if ($item->contact_sales)
                            <span class="px-2 py-0.5 text-xs rounded-sm bg-neutral-500/20">{{ __('Sales team') }}</span>
                        @endif
                        <span class="text-xs dark:text-neutral-400">{{ trans_choice(':count customer|:count customers', (int) ($customers[$item->slug] ?? 0), ['count' => (int) ($customers[$item->slug] ?? 0)]) }}</span>
                        <div class="flex-1"></div>
                        @if ($canEdit)
                            <x-forms.button wire:click="move('{{ $item->slug }}', -1)" :disabled="$loop->first" title="{{ __('Move up') }}">↑</x-forms.button>
                            <x-forms.button wire:click="move('{{ $item->slug }}', 1)" :disabled="$loop->last" title="{{ __('Move down') }}">↓</x-forms.button>
                            <x-forms.button wire:click="edit('{{ $item->slug }}')">{{ __('Edit') }}</x-forms.button>
                            <x-forms.button wire:click="create('{{ $item->slug }}')">{{ __('Duplicate') }}</x-forms.button>
                            <x-forms.button isError wire:click="confirmDelete('{{ $item->slug }}')">{{ __('Remove') }}</x-forms.button>
                        @endif
                    </div>
                    <div class="text-sm dark:text-neutral-300">
                        {{ $item->price_monthly_cents ? formatPlatformMoney($item->price_monthly_cents).' / '.__('month') : __('no monthly price') }}
                        · {{ $item->price_yearly_cents ? formatPlatformMoney($item->price_yearly_cents).' / '.__('year') : __('no yearly price') }}
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs dark:text-neutral-400">
                        @foreach (\App\Services\Platform\PlanCatalog::quotaLines($item->toCatalogEntry()) as $line)
                            <span><strong class="dark:text-white">{{ $line['value'] }}</strong> {{ $line['label'] }}</span>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        @foreach (\App\Services\Platform\PlanCatalog::featureLines($item->toCatalogEntry()) as $line)
                            <span @class(['dark:text-white' => $line['included'], 'line-through dark:text-neutral-600 text-neutral-400' => ! $line['included']])>
                                {{ $line['included'] ? '✓' : '✗' }} {{ $line['label'] }}
                            </span>
                        @endforeach
                        @foreach (\App\Services\Platform\PlanCatalog::extraLines($item->toCatalogEntry()) as $extra)
                            <span class="dark:text-white">+ {{ $extra['text'] }}</span>
                        @endforeach
                    </div>
                    @if ($deleting === $item->slug)
                        <div class="flex flex-col gap-2 p-3 border rounded-sm border-error/60">
                            <div class="text-sm">
                                @if ((int) ($customers[$item->slug] ?? 0) > 0)
                                    {{ __('Move its customers to another plan before removing it. Open invoices of this plan are cancelled; the next renewal charges the new plan.') }}
                                @else
                                    {{ __('No customer is on this plan.') }}
                                @endif
                            </div>
                            @if ((int) ($customers[$item->slug] ?? 0) > 0)
                                <x-forms.select id="replacement" label="{{ __('Move the customers to') }}">
                                    @foreach ($plans->where('slug', '!=', $item->slug) as $other)
                                        <option value="{{ $other->slug }}">{{ $other->name }}</option>
                                    @endforeach
                                </x-forms.select>
                            @endif
                            <div class="flex gap-2">
                                <x-forms.button isError wire:click="delete">{{ __('Remove the plan') }}</x-forms.button>
                                <x-forms.button wire:click="$set('deleting', null)">{{ __('Cancel') }}</x-forms.button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <form wire:submit="save" class="flex flex-col gap-6">
            <section class="flex flex-col gap-2">
                <h4>{{ $editing === 'new' ? __('New plan') : __('Edit :plan', ['plan' => $plan['name'] ?? '']) }}</h4>
                <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                    <x-forms.input id="plan.name" label="{{ __('Name') }}" required />
                    @if ($editing === 'new')
                        <x-forms.input id="plan.slug" label="{{ __('Identifier') }}" placeholder="{{ __('generated from the name') }}"
                            helper="{{ __('Lowercase letters, numbers, _ and -. It can not change later.') }}" />
                    @else
                        <x-forms.input id="plan.slug" label="{{ __('Identifier') }}" disabled />
                    @endif
                </div>
                <x-forms.textarea id="plan.description" label="{{ __('Description') }}" rows="2" />
                <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                    <x-forms.input id="plan.price_monthly" label="{{ __('Monthly price') }}" placeholder="49,90"
                        helper="{{ __('Empty: the plan has no monthly price (sold by the sales team or yearly only).') }}" />
                    <x-forms.input id="plan.price_yearly" label="{{ __('Yearly price') }}" placeholder="499,00" />
                    @if ($stripe)
                        <x-forms.input id="plan.stripe_price_monthly" label="{{ __('Stripe price id (monthly)') }}" />
                        <x-forms.input id="plan.stripe_price_yearly" label="{{ __('Stripe price id (yearly)') }}" />
                    @endif
                </div>
                <div class="flex flex-wrap gap-6">
                    <x-forms.checkbox id="plan.is_public" label="{{ __('Public (listed and sold on the pricing pages)') }}" />
                    <x-forms.checkbox id="plan.is_highlighted" label="{{ __('Recommended (highlighted)') }}" />
                    <x-forms.checkbox id="plan.contact_sales" label="{{ __('Sold by the sales team (no self-service)') }}" />
                </div>
            </section>

            <section class="flex flex-col gap-2">
                <h4>{{ __('Limits') }}</h4>
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    @foreach ($quotaDefinitions as $key => $definition)
                        <div wire:key="quota-{{ $key }}" class="flex flex-col gap-1">
                            <div class="flex items-end gap-2">
                                <div class="flex-1">
                                    <x-forms.input id="quotas.{{ $key }}" type="number" step="any"
                                        label="{{ __($definition['title']) }}"
                                        helper="{{ __($definition['help']) }}" :disabled="($unlimited[$key] ?? false)" />
                                </div>
                                @if ($definition['unlimited'])
                                    <x-forms.checkbox id="unlimited.{{ $key }}" label="{{ __('Unlimited') }}" instantSave="$refresh" />
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="flex flex-col gap-2">
                <h4>{{ __('Capabilities') }}</h4>
                <div class="grid grid-cols-1 gap-1 lg:grid-cols-2">
                    @foreach ($featureDefinitions as $key => $definition)
                        <x-forms.checkbox wire:key="feature-{{ $key }}" id="features.{{ $key }}" label="{{ __($definition['label']) }}" helper="{{ __($definition['help']) }}" />
                    @endforeach
                </div>
            </section>

            <section class="flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <h4>{{ __('Other items') }}</h4>
                    <x-forms.button wire:click="addExtra">{{ __('Add item') }}</x-forms.button>
                </div>
                <div class="text-xs dark:text-neutral-400">{{ __('Anything else the plan offers (support, SLA, onboarding...), shown on the pricing pages with its explanation.') }}</div>
                @foreach ($extras as $index => $extra)
                    <div wire:key="extra-{{ $index }}" class="flex flex-wrap items-end gap-2">
                        <div class="flex-1 min-w-64"><x-forms.input id="extras.{{ $index }}.text" label="{{ __('Item') }}" placeholder="{{ __('Support by e-mail in one business day') }}" /></div>
                        <div class="flex-1 min-w-64"><x-forms.input id="extras.{{ $index }}.help" label="{{ __('Explanation (optional)') }}" /></div>
                        <x-forms.button isError wire:click="removeExtra({{ $index }})">{{ __('Remove') }}</x-forms.button>
                    </div>
                @endforeach
            </section>

            <div class="flex gap-2">
                <x-forms.button type="submit" isHighlighted>{{ __('Save') }}</x-forms.button>
                <x-forms.button wire:click="cancel">{{ __('Cancel') }}</x-forms.button>
            </div>
        </form>
    @endif
</div>
