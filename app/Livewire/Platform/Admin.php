<?php

namespace App\Livewire\Platform;

use App\Actions\Platform\ProvisionTenant;
use App\Models\AuditLog;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Server;
use App\Services\Platform\Impersonation;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PlatformGate;
use App\Services\Platform\TenantLifecycle;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operator console of the platform: regions, node assignment, tenants and audit trail.
 */
class Admin extends Component
{
    #[Url]
    public string $tab = 'tenants';

    public string $regionSlug = '';

    public string $regionName = '';

    /**
     * @var array<int, string|null>
     */
    public array $nodeRegions = [];

    /**
     * @var array<int, string|null>
     */
    public array $nodeDedicatedTeams = [];

    public ?int $editingTenantId = null;

    public ?string $editingPlan = null;

    /** @var array<string, string> quota => value for this customer only (empty: the plan's) */
    public array $editingQuotas = [];

    /** @var array<string, string> feature => "" (the plan's), "1" (included) or "0" (not included) */
    public array $editingFeatures = [];

    public string $editingPeriodEnd = '';

    public bool $editingExempt = false;

    public string $editingNotes = '';

    public string $search = '';

    public function mount()
    {
        if (! $this->authorized()) {
            return redirect()->route('dashboard');
        }
        $this->loadNodes();
    }

    private function authorized(): bool
    {
        return isPlatform() && auth()->check() && (int) currentTeam()?->id === 0 && auth()->user()->isInstanceAdmin();
    }

    private function guard(): void
    {
        abort_unless($this->authorized(), 403);
    }

    private function loadNodes(): void
    {
        foreach (Server::query()->where('team_id', 0)->get() as $server) {
            $this->nodeRegions[$server->id] = $server->platform_region_id ? (string) $server->platform_region_id : '';
            $this->nodeDedicatedTeams[$server->id] = $server->platform_dedicated_team_id ? (string) $server->platform_dedicated_team_id : '';
        }
    }

    public function createRegion(): void
    {
        $this->guard();
        $this->validate([
            'regionSlug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-]+$/'],
            'regionName' => ['required', 'string', 'max:255'],
        ]);
        $region = PlatformRegion::create([
            'slug' => $this->regionSlug,
            'name' => $this->regionName,
            'is_default' => PlatformRegion::query()->doesntExist(),
        ]);
        audit_log('platform.region.created', $region, ['slug' => $region->slug], 0);
        $this->reset(['regionSlug', 'regionName']);
        $this->dispatch('success', __('Region created.'));
    }

    public ?int $editingRegionId = null;

    public string $editingRegionName = '';

    public string $editingRegionLogsUrl = '';

    public function editRegion(int $regionId): void
    {
        $this->guard();
        $region = PlatformRegion::findOrFail($regionId);
        $this->editingRegionId = $region->id;
        $this->editingRegionName = (string) $region->name;
        $this->editingRegionLogsUrl = (string) $region->logs_url;
    }

    public function saveRegion(): void
    {
        $this->guard();
        $this->validate([
            'editingRegionName' => ['required', 'string', 'max:255'],
            'editingRegionLogsUrl' => ['nullable', 'url:http,https', 'max:255'],
        ]);
        $region = PlatformRegion::findOrFail($this->editingRegionId);
        $region->update([
            'name' => $this->editingRegionName,
            'logs_url' => filled($this->editingRegionLogsUrl) ? rtrim($this->editingRegionLogsUrl, '/') : null,
        ]);
        audit_log('platform.region.updated', $region, ['name' => $region->name], 0);
        $this->reset(['editingRegionId', 'editingRegionName', 'editingRegionLogsUrl']);
        $this->dispatch('success', __('Region saved.'));
    }

    public function toggleRegion(int $regionId, string $field): void
    {
        $this->guard();
        $region = PlatformRegion::findOrFail($regionId);
        if ($field === 'is_default') {
            $region->update(['is_default' => true, 'is_active' => true]);
        } elseif ($field === 'is_active') {
            $region->update(['is_active' => ! $region->is_active]);
        }
    }

    public function saveNode(int $serverId): void
    {
        $this->guard();
        $server = Server::query()->where('team_id', 0)->findOrFail($serverId);
        $regionId = $this->nodeRegions[$serverId] ?? null;
        $dedicated = $this->nodeDedicatedTeams[$serverId] ?? null;
        $server->forceFill([
            'platform_region_id' => filled($regionId) ? (int) $regionId : null,
            'platform_dedicated_team_id' => filled($dedicated) ? (int) $dedicated : null,
        ])->save();
        audit_log('platform.node.assigned', $server, ['region_id' => $regionId, 'dedicated_team_id' => $dedicated], 0);
        if (filled($regionId)) {
            PlatformTenant::query()->where('platform_region_id', (int) $regionId)->whereNotNull('provisioned_at')->pluck('team_id')
                ->each(fn ($teamId) => ProvisionTenant::dispatch($teamId));
        }
        $this->dispatch('success', __('Node saved.'));
    }

    public function provision(int $tenantId): void
    {
        $this->guard();
        $tenant = PlatformTenant::findOrFail($tenantId);
        ProvisionTenant::dispatch($tenant->team_id);
        $this->dispatch('success', __('Provisioning queued.'));
    }

    public function suspend(int $tenantId, bool $hard = false): void
    {
        $this->guard();
        $tenant = PlatformTenant::findOrFail($tenantId);
        if ($hard) {
            TenantLifecycle::hardSuspend($tenant, TenantLifecycle::REASON_MANUAL);
        } else {
            TenantLifecycle::suspend($tenant, TenantLifecycle::REASON_MANUAL);
        }
        $this->dispatch('success', __('Tenant suspended.'));
    }

    public function reactivate(int $tenantId): void
    {
        $this->guard();
        TenantLifecycle::reactivate(PlatformTenant::findOrFail($tenantId));
        $this->dispatch('success', __('Tenant reactivated.'));
    }

    public function editTenant(int $tenantId): void
    {
        $this->guard();
        $tenant = PlatformTenant::findOrFail($tenantId);
        $this->resetErrorBag();
        $this->editingTenantId = $tenant->id;
        $this->editingPlan = $tenant->plan;
        $overrides = (array) $tenant->quota_overrides;
        $this->editingQuotas = [];
        foreach (array_keys(PlanCatalog::QUOTAS) as $key) {
            $this->editingQuotas[$key] = is_numeric($overrides[$key] ?? null) ? (string) $overrides[$key] : '';
        }
        $this->editingFeatures = [];
        foreach (array_keys(PlanCatalog::FEATURES) as $key) {
            $value = data_get($overrides, "features.{$key}");
            $this->editingFeatures[$key] = is_null($value) ? '' : ((bool) $value ? '1' : '0');
        }
        $this->editingPeriodEnd = (string) $tenant->current_period_end?->format('Y-m-d');
        $this->editingExempt = (bool) $tenant->billing_exempt;
        $this->editingNotes = (string) $tenant->operator_notes;
    }

    /**
     * Everything about a customer's account: plan, limits and capabilities of their own, billing
     * period, courtesy (no invoices) and notes. A pending account given a plan is activated.
     */
    public function saveTenant(): void
    {
        $this->guard();
        $tenant = PlatformTenant::findOrFail($this->editingTenantId);
        $rules = [
            'editingPlan' => ['nullable', 'string'],
            'editingPeriodEnd' => ['nullable', 'date_format:Y-m-d'],
            'editingExempt' => ['boolean'],
            'editingNotes' => ['nullable', 'string', 'max:5000'],
            'editingFeatures.*' => ['nullable', 'in:,0,1'],
        ];
        foreach (PlanCatalog::QUOTAS as $key => $definition) {
            $rules["editingQuotas.{$key}"] = ['nullable', 'numeric', 'min:0', 'max:'.$definition['max']];
        }
        $this->validate($rules, [], collect(PlanCatalog::QUOTAS)->mapWithKeys(fn ($definition, $key) => ["editingQuotas.{$key}" => __($definition['title'])])->all());
        if (filled($this->editingPlan) && ! PlanCatalog::exists($this->editingPlan)) {
            $this->addError('editingPlan', __('Unknown plan.'));

            return;
        }
        $overrides = [];
        foreach (PlanCatalog::QUOTAS as $key => $definition) {
            $value = trim((string) ($this->editingQuotas[$key] ?? ''));
            if ($value !== '') {
                $overrides[$key] = $definition['format'] === 'decimal' ? round((float) $value, 3) : (int) $value;
            }
        }
        foreach (array_keys(PlanCatalog::FEATURES) as $key) {
            $value = (string) ($this->editingFeatures[$key] ?? '');
            if ($value !== '') {
                $overrides['features'][$key] = $value === '1';
            }
        }
        $activate = filled($this->editingPlan) && in_array($tenant->status, [PlatformTenant::STATUS_PENDING, PlatformTenant::STATUS_CANCELLED], true);
        $periodEnd = filled($this->editingPeriodEnd) ? \Carbon\Carbon::createFromFormat('Y-m-d', $this->editingPeriodEnd)->endOfDay() : $tenant->current_period_end;
        if ($activate && (is_null($periodEnd) || $periodEnd->isPast())) {
            $periodEnd = now()->addMonthNoOverflow();
        }
        $tenant->update([
            'plan' => $this->editingPlan ?: null,
            'quota_overrides' => $overrides ?: null,
            'billing_exempt' => $this->editingExempt,
            'operator_notes' => trim($this->editingNotes) ?: null,
            'current_period_end' => $periodEnd,
            'current_period_start' => $activate ? now() : $tenant->current_period_start,
            'billing_interval' => $tenant->billing_interval ?: PlanCatalog::INTERVAL_MONTHLY,
            'status' => $activate ? PlatformTenant::STATUS_ACTIVE : $tenant->status,
            'suspension_reason' => $activate ? null : $tenant->suspension_reason,
            'suspended_at' => $activate ? null : $tenant->suspended_at,
        ]);
        $tenant = $tenant->fresh();
        \App\Services\Platform\PlatformBilling::syncCoolifySubscription($tenant);
        if ($activate && is_null($tenant->provisioned_at)) {
            ProvisionTenant::dispatch($tenant->team_id);
        }
        audit_log('platform.tenant.edited', $tenant, [
            'plan' => $tenant->plan,
            'overrides' => $overrides ?: null,
            'billing_exempt' => $tenant->billing_exempt,
            'period_end' => $tenant->current_period_end?->toDateString(),
            'activated' => $activate,
        ], $tenant->team_id);
        $this->editingTenantId = null;
        $this->dispatch('success', __('Tenant saved.'));
    }

    /**
     * Opens the dashboard as a user of the customer (see Impersonation).
     */
    public function impersonate(int $tenantId, int $userId)
    {
        $this->guard();
        $tenant = PlatformTenant::with('team')->findOrFail($tenantId);
        $user = $tenant->team?->members()->where('users.id', $userId)->first();
        abort_if(is_null($user), 404);
        if (! Impersonation::canImpersonate(auth()->user(), $user)) {
            $this->dispatch('error', __('This user can not be accessed.'));

            return null;
        }
        Impersonation::start($user, $tenant->team);

        return redirect()->route('dashboard');
    }

    public function render()
    {
        $tenants = PlatformTenant::query()
            ->with(['team.members', 'region'])
            ->when($this->search !== '', fn ($query) => $query->whereHas('team', fn ($team) => $team->where('name', 'ilike', '%'.$this->search.'%')))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (PlatformTenant $tenant) {
                $tenant->setAttribute('allocation', PlatformGate::allocated($tenant->team_id, $tenant->quotas()));

                return $tenant;
            });

        return view('livewire.platform.admin', [
            'regions' => PlatformRegion::query()->withCount('servers')->orderBy('name')->get(),
            'nodes' => Server::query()->where('team_id', 0)->with(['settings', 'platformRegion'])->orderBy('name')->get(),
            'tenants' => $tenants,
            'plans' => PlanCatalog::all(),
            'auditLogs' => $this->tab === 'audit' ? AuditLog::query()->latest('id')->limit(200)->get() : collect(),
        ]);
    }
}
