<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformRegion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PlatformRegion $region) {
            $region->slug = str($region->slug)->lower()->slug()->value();
        });

        static::saved(function (PlatformRegion $region) {
            if ($region->is_default) {
                PlatformRegion::query()->whereKeyNot($region->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public static function defaultRegion(): ?PlatformRegion
    {
        return PlatformRegion::query()->where('is_active', true)->where('is_default', true)->first()
            ?? PlatformRegion::query()->where('is_active', true)->where('slug', config('platform.default_region'))->first()
            ?? PlatformRegion::query()->where('is_active', true)->orderBy('id')->first();
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'platform_region_id');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(PlatformTenant::class, 'platform_region_id');
    }

    /**
     * Shared nodes of this region that can receive tenant workloads.
     */
    public function sharedNodes()
    {
        return $this->servers()
            ->where('team_id', 0)
            ->whereNull('platform_dedicated_team_id')
            ->whereRelation('settings', 'is_build_server', false)
            ->whereRelation('settings', 'force_disabled', false);
    }
}
