<div class="flex flex-col gap-4">
    <x-slot:title>
        {{ __('Platform') }} | {{ config('platform.name') }}
    </x-slot>
    <div>
        <h1>{{ __('Platform') }}</h1>
        <div class="subtitle">{{ __('Customers, plans, billing, regions, nodes and audit trail of the platform.') }}</div>
    </div>
    <div class="flex gap-2">
        @foreach (['tenants' => __('Tenants'), 'plans' => __('Plans'), 'billing' => __('Billing'), 'nodes' => __('Regions & nodes'), 'settings' => __('Settings'), 'audit' => __('Audit log')] as $key => $label)
            <x-forms.button wire:click="$set('tab', '{{ $key }}')" :isHighlighted="$tab === $key">{{ $label }}</x-forms.button>
        @endforeach
    </div>

    @if ($tab === 'tenants')
        <x-forms.input id="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search team name...') }}" />
        <div class="overflow-x-auto">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Team') }}</th>
                        <th>{{ __('Plan') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Region') }}</th>
                        <th>{{ __('Reserved') }}</th>
                        <th>{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tenants as $tenant)
                        <tr wire:key="tenant-{{ $tenant->id }}">
                            <td>{{ $tenant->team?->name }} <span class="text-xs">#{{ $tenant->team_id }}</span></td>
                            <td>{{ $tenant->planName() }}@if ($tenant->billing_exempt) <span class="text-xs">({{ __('courtesy') }})</span>@endif</td>
                            <td>{{ $tenant->status }}@if ($tenant->suspension_reason) <span class="text-xs">({{ $tenant->suspension_reason }})</span>@endif</td>
                            <td>{{ $tenant->region?->slug ?? '—' }}</td>
                            <td class="text-xs">{{ $tenant->allocation['cpus'] }} vCPU · {{ $tenant->allocation['memory_mb'] }} MB · {{ $tenant->allocation['containers'] }} ctr</td>
                            <td class="flex flex-wrap gap-1">
                                <x-forms.button wire:click="editTenant({{ $tenant->id }})">{{ __('Edit') }}</x-forms.button>
                                <x-forms.button wire:click="provision({{ $tenant->id }})">{{ __('Provision') }}</x-forms.button>
                                @if (in_array($tenant->status, ['suspended', 'hard_suspended', 'cancelled', 'limited']))
                                    <x-forms.button wire:click="reactivate({{ $tenant->id }})">{{ __('Reactivate') }}</x-forms.button>
                                @else
                                    <x-forms.button isError wire:click="suspend({{ $tenant->id }})" wire:confirm="{{ __('Suspend deployments of this tenant?') }}">{{ __('Suspend') }}</x-forms.button>
                                    <x-forms.button isError wire:click="suspend({{ $tenant->id }}, true)" wire:confirm="{{ __('Stop all containers of this tenant?') }}">{{ __('Stop all') }}</x-forms.button>
                                @endif
                            </td>
                        </tr>
                        @if ($editingTenantId === $tenant->id)
                            <tr wire:key="tenant-edit-{{ $tenant->id }}">
                                <td colspan="6">
                                    <form wire:submit="saveTenant" class="flex flex-col gap-4 py-2">
                                        <div class="grid grid-cols-1 gap-2 lg:grid-cols-3">
                                            <x-forms.select id="editingPlan" label="{{ __('Plan') }}" helper="{{ __('Changes now, without charge. An account without an active plan is activated.') }}">
                                                <option value="">{{ __('No plan') }}</option>
                                                @foreach ($plans as $key => $plan)
                                                    <option value="{{ $key }}">{{ $plan['name'] }}{{ \App\Services\Platform\PlanCatalog::isPublic($plan) ? '' : ' ('.__('hidden').')' }}</option>
                                                @endforeach
                                            </x-forms.select>
                                            <x-forms.input id="editingPeriodEnd" type="date" label="{{ __('Current period ends on') }}" helper="{{ __('Move it forward to give free days; the next invoice is issued on this date.') }}" />
                                            <x-forms.checkbox id="editingExempt" label="{{ __('Courtesy account (never charged)') }}" helper="{{ __('The plan renews without invoices until you turn this off.') }}" />
                                        </div>
                                        <div>
                                            <h4>{{ __('Limits of this customer') }}</h4>
                                            <div class="text-xs dark:text-neutral-400">{{ __('Empty: the value of the plan. 0 means unlimited where the plan allows it.') }}</div>
                                            <div class="grid grid-cols-2 gap-2 pt-2 lg:grid-cols-4">
                                                @foreach (\App\Services\Platform\PlanCatalog::QUOTAS as $key => $definition)
                                                    <x-forms.input wire:key="override-{{ $key }}" id="editingQuotas.{{ $key }}" type="number" step="any"
                                                        label="{{ __($definition['title']) }}"
                                                        placeholder="{{ __('plan: :value', ['value' => \App\Services\Platform\PlanCatalog::formatQuota($key, (float) data_get($plans, ($editingPlan ?: $tenant->plan).'.quotas.'.$key, 0))]) }}" />
                                                @endforeach
                                            </div>
                                        </div>
                                        <div>
                                            <h4>{{ __('Capabilities of this customer') }}</h4>
                                            <div class="grid grid-cols-1 gap-2 pt-2 lg:grid-cols-3">
                                                @foreach (\App\Services\Platform\PlanCatalog::FEATURES as $key => $definition)
                                                    <x-forms.select wire:key="override-feature-{{ $key }}" id="editingFeatures.{{ $key }}" label="{{ __($definition['label']) }}">
                                                        <option value="">{{ __('As in the plan') }}</option>
                                                        <option value="1">{{ __('Included') }}</option>
                                                        <option value="0">{{ __('Not included') }}</option>
                                                    </x-forms.select>
                                                @endforeach
                                            </div>
                                        </div>
                                        <x-forms.textarea id="editingNotes" label="{{ __('Notes (only operators see them)') }}" rows="2" />
                                        <div>
                                            <h4>{{ __('Users') }}</h4>
                                            <div class="flex flex-col gap-1 pt-1">
                                                @foreach ($tenant->team?->members ?? [] as $member)
                                                    <div wire:key="member-{{ $tenant->id }}-{{ $member->id }}" class="flex flex-wrap items-center gap-2 text-sm">
                                                        <span>{{ $member->name }} &lt;{{ $member->email }}&gt; · {{ $member->pivot->role }}</span>
                                                        <x-forms.button wire:click="impersonate({{ $tenant->id }}, {{ $member->id }})"
                                                            wire:confirm="{{ __('Open the dashboard as :user? Everything you do is recorded with your name.', ['user' => $member->email]) }}">
                                                            {{ __('Access as this user') }}
                                                        </x-forms.button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                        <div class="flex gap-2">
                                            <x-forms.button type="submit" isHighlighted>{{ __('Save') }}</x-forms.button>
                                            <x-forms.button wire:click="$set('editingTenantId', null)">{{ __('Cancel') }}</x-forms.button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'plans')
        <livewire:platform.admin-plans />
    @elseif ($tab === 'billing')
        <livewire:platform.admin-billing />
    @elseif ($tab === 'settings')
        <livewire:platform.admin-settings />
    @elseif ($tab === 'nodes')
        <h3>{{ __('Regions') }}</h3>
        <form wire:submit="createRegion" class="flex flex-wrap items-end gap-2">
            <x-forms.input id="regionSlug" label="{{ __('Slug') }}" placeholder="sa-east-1" required />
            <x-forms.input id="regionName" label="{{ __('Name') }}" placeholder="São Paulo" required />
            <x-forms.button type="submit">{{ __('Add region') }}</x-forms.button>
        </form>
        <div class="flex flex-col gap-2">
            @foreach ($regions as $region)
                <div wire:key="region-{{ $region->id }}" class="flex flex-wrap items-center gap-2 p-2 border rounded-sm dark:border-coolgray-300">
                    <strong class="dark:text-white">{{ $region->name }}</strong> <span class="text-xs">{{ $region->slug }}</span>
                    <span class="text-xs">{{ $region->servers_count }} {{ __('nodes') }}</span>
                    <span class="text-xs">{{ __('Logs') }}: {{ $region->logs_url ?: '—' }}</span>
                    @if ($region->is_default)
                        <span class="text-xs text-success">{{ __('default') }}</span>
                    @else
                        <x-forms.button wire:click="toggleRegion({{ $region->id }}, 'is_default')">{{ __('Make default') }}</x-forms.button>
                    @endif
                    <x-forms.button wire:click="toggleRegion({{ $region->id }}, 'is_active')">{{ $region->is_active ? __('Disable') : __('Enable') }}</x-forms.button>
                    <x-forms.button wire:click="editRegion({{ $region->id }})">{{ __('Edit') }}</x-forms.button>
                    @if ($editingRegionId === $region->id)
                        <form wire:submit="saveRegion" class="flex flex-wrap items-end w-full gap-2">
                            <x-forms.input id="editingRegionName" label="{{ __('Name') }}" required />
                            <x-forms.input id="editingRegionLogsUrl" label="{{ __('Loki of the region (log history)') }}" placeholder="http://10.20.0.10:3100" />
                            <x-forms.button type="submit">{{ __('Save') }}</x-forms.button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
        <h3 class="pt-4">{{ __('Nodes') }}</h3>
        <div class="text-xs dark:text-neutral-400">{{ __('Servers of the operator team. Assign a region to make a node receive tenant workloads; set a team id to dedicate it (Enterprise).') }}</div>
        <div class="flex flex-col gap-2">
            @foreach ($nodes as $node)
                <div wire:key="node-{{ $node->id }}" class="flex flex-wrap items-end gap-2 p-2 border rounded-sm dark:border-coolgray-300">
                    <div class="w-48">
                        <div class="dark:text-white">{{ $node->name }}</div>
                        <div class="text-xs">{{ $node->ip }} · {{ $node->settings?->is_reachable ? __('reachable') : __('unreachable') }}</div>
                    </div>
                    <x-forms.select id="nodeRegions.{{ $node->id }}" label="{{ __('Region') }}">
                        <option value="">{{ __('Not a platform node') }}</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region->id }}">{{ $region->name }}</option>
                        @endforeach
                    </x-forms.select>
                    <x-forms.input id="nodeDedicatedTeams.{{ $node->id }}" label="{{ __('Dedicated to team id') }}" placeholder="—" />
                    <x-forms.button wire:click="saveNode({{ $node->id }})">{{ __('Save') }}</x-forms.button>
                </div>
            @endforeach
        </div>
    @else
        <div class="overflow-x-auto">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('When') }}</th>
                        <th>{{ __('Team') }}</th>
                        <th>{{ __('Actor') }}</th>
                        <th>{{ __('Action') }}</th>
                        <th>{{ __('Subject') }}</th>
                        <th>{{ __('Details') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($auditLogs as $log)
                        <tr wire:key="audit-{{ $log->id }}">
                            <td class="text-xs">{{ $log->created_at }}</td>
                            <td>{{ $log->team_id }}</td>
                            <td class="text-xs">{{ $log->actor_type }} {{ $log->user_id ? '#'.$log->user_id : '' }} {{ $log->ip_address }}</td>
                            <td>{{ $log->action }}</td>
                            <td class="text-xs">{{ class_basename((string) $log->subject_type) }} {{ $log->subject_id }}</td>
                            <td class="text-xs">{{ $log->metadata ? json_encode($log->metadata) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
