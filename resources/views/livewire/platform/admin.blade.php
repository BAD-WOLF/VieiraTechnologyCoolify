<div class="flex flex-col gap-4">
    <x-slot:title>
        {{ __('Platform') }} | {{ config('platform.name') }}
    </x-slot>
    <div>
        <h1>{{ __('Platform') }}</h1>
        <div class="subtitle">{{ __('Regions, nodes, tenants and audit trail of the multi-tenant platform.') }}</div>
    </div>
    <div class="flex gap-2">
        @foreach (['tenants' => __('Tenants'), 'billing' => __('Billing'), 'nodes' => __('Regions & nodes'), 'audit' => __('Audit log')] as $key => $label)
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
                            <td>{{ $tenant->planName() }}</td>
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
                                    <form wire:submit="saveTenant" class="flex flex-col gap-2">
                                        <x-forms.select id="editingPlan" label="{{ __('Plan') }}">
                                            <option value="">{{ __('No plan') }}</option>
                                            @foreach ($plans as $key => $plan)
                                                <option value="{{ $key }}">{{ $plan['name'] }}</option>
                                            @endforeach
                                        </x-forms.select>
                                        <x-forms.textarea id="editingOverrides" label="{{ __('Quota overrides (JSON)') }}" helper='{"cpus": 4, "memory_mb": 8192, "features": {"sso": true}}' />
                                        <div class="flex gap-2">
                                            <x-forms.button type="submit">{{ __('Save') }}</x-forms.button>
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
    @elseif ($tab === 'billing')
        <livewire:platform.admin-billing />
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
