<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

/**
 * Endpoint that receives the platform events of a team (wiki 9, "Plugins e Hooks").
 * Operator webhooks (team 0) can receive the events of every team (all_teams).
 */
class PlatformWebhook extends Model
{
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'all_teams' => 'boolean',
            'enabled' => 'boolean',
            'last_delivery_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformWebhook $webhook) {
            $webhook->uuid ??= (string) new Cuid2;
            $webhook->secret ??= self::newSecret();
        });
        static::saved(function (): void {
            Cache::forget('platform-webhooks:subscribers');
        });
        static::deleted(function (): void {
            Cache::forget('platform-webhooks:subscribers');
        });
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PlatformWebhookDelivery::class);
    }

    public function listensTo(string $eventType): bool
    {
        foreach ((array) $this->events as $pattern) {
            if (Str::is((string) $pattern, $eventType)) {
                return true;
            }
        }

        return false;
    }
}
