<?php

namespace App\Models;

use App\Services\Platform\PlanCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformTenant extends Model
{
    /**
     * Never serialized (API responses, Livewire): payer data and the hash of the OTLP token.
     *
     * @var list<string>
     */
    protected $hidden = ['billing_profile', 'otlp_token_hash'];

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LIMITED = 'limited';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_HARD_SUSPENDED = 'hard_suspended';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses that block new deployments, starts and resource creation.
     */
    public const BLOCKING_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_LIMITED,
        self::STATUS_SUSPENDED,
        self::STATUS_HARD_SUSPENDED,
        self::STATUS_CANCELLED,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quota_overrides' => 'array',
            'stopped_resources' => 'array',
            'past_due_since' => 'datetime',
            'suspended_at' => 'datetime',
            'hard_suspended_at' => 'datetime',
            'quota_warning_notified_at' => 'datetime',
            'quota_exceeded_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'provisioned_at' => 'datetime',
            'billing_profile' => 'encrypted:array',
            'cancel_at_period_end' => 'boolean',
            'otlp_token_created_at' => 'datetime',
        ];
    }

    public static function forTeam(Team|int $team): PlatformTenant
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        return PlatformTenant::query()->firstOrCreate(
            ['team_id' => $teamId],
            ['status' => self::STATUS_PENDING],
        );
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(PlatformRegion::class, 'platform_region_id');
    }

    public function hasPlan(): bool
    {
        return filled($this->plan) && PlanCatalog::exists($this->plan);
    }

    /**
     * @return array<string, mixed>
     */
    public function planDefinition(): array
    {
        return PlanCatalog::get($this->plan) ?? [];
    }

    public function planName(): string
    {
        return data_get($this->planDefinition(), 'name', __('No plan'));
    }

    /**
     * Effective quotas: plan quotas merged with per-tenant overrides (enterprise deals).
     *
     * @return array{cpus: float, memory_mb: int, storage_gb: int, resources: int, containers: int, deployments_per_day: int, custom_domains: int, egress_gb: int, pids_per_container: int}
     */
    public function quotas(): array
    {
        $quotas = array_merge(PlanCatalog::emptyQuotas(), data_get($this->planDefinition(), 'quotas', []));
        foreach ((array) $this->quota_overrides as $key => $value) {
            if (array_key_exists($key, $quotas) && is_numeric($value)) {
                $quotas[$key] = $key === 'cpus' ? (float) $value : (int) $value;
            }
        }

        return $quotas;
    }

    public function quota(string $key): float|int
    {
        return $this->quotas()[$key] ?? 0;
    }

    public function hasFeature(string $feature): bool
    {
        $override = data_get($this->quota_overrides, "features.{$feature}");
        if (! is_null($override)) {
            return (bool) $override;
        }

        return (bool) data_get($this->planDefinition(), "features.{$feature}", false);
    }

    public function isBlocked(): bool
    {
        return in_array($this->status, self::BLOCKING_STATUSES, true);
    }

    public function blockReason(): ?string
    {
        return match ($this->status) {
            self::STATUS_PENDING => __('Your team has no active plan. Choose a plan to start deploying.'),
            self::STATUS_LIMITED => __('Your team reached 100% of a plan quota. Free resources or upgrade your plan to deploy again.'),
            self::STATUS_SUSPENDED => __('Deployments are suspended because an invoice is overdue. Pay the open invoice to resume.'),
            self::STATUS_HARD_SUSPENDED => __('Your containers were stopped because an invoice is overdue for too long. Pay the open invoice to restore them.'),
            self::STATUS_CANCELLED => __('Your subscription was cancelled. Subscribe again to deploy.'),
            default => null,
        };
    }

    public function networkName(): string
    {
        return config('platform.tenant_network_prefix', 'tenant').'-'.$this->team_id;
    }
}
