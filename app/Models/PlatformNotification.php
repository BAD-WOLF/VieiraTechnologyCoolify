<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A notification of the bell: an announcement of the owner to an audience, or an event of one
 * account (team). Plain text: the title, the body (line breaks kept) and an optional link.
 */
class PlatformNotification extends Model
{
    use HasFactory;

    public const KIND_NEWS = 'news';

    public const KIND_PROMOTION = 'promotion';

    public const KIND_UPDATE = 'update';

    public const KIND_MAINTENANCE = 'maintenance';

    public const KIND_ALERT = 'alert';

    public const KIND_BILLING = 'billing';

    public const KIND_ACCOUNT = 'account';

    /** What the owner can send, in the order of the form. */
    public const ANNOUNCEMENT_KINDS = [self::KIND_NEWS, self::KIND_PROMOTION, self::KIND_UPDATE, self::KIND_MAINTENANCE, self::KIND_ALERT];

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_CUSTOMERS = 'customers';

    public const AUDIENCE_OPERATORS = 'operators';

    public const AUDIENCE_PLAN = 'plan';

    public const AUDIENCE_TEAM = 'team';

    public const AUDIENCES = [self::AUDIENCE_ALL, self::AUDIENCE_CUSTOMERS, self::AUDIENCE_OPERATORS, self::AUDIENCE_PLAN, self::AUDIENCE_TEAM];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (PlatformNotification $notification) {
            $notification->uuid ??= (string) Str::uuid();
            $notification->published_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(PlatformNotificationRead::class);
    }

    /**
     * Published and not expired.
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('published_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Seen by the members of a team: everyone, its kind of account, its plan or the team itself.
     */
    public function scopeVisibleTo(Builder $query, ?int $teamId, ?string $plan = null): void
    {
        $query->where(function (Builder $query) use ($teamId, $plan) {
            $query->where('audience', self::AUDIENCE_ALL);
            if (is_null($teamId)) {
                return;
            }
            $query->orWhere('audience', $teamId === 0 ? self::AUDIENCE_OPERATORS : self::AUDIENCE_CUSTOMERS)
                ->orWhere(fn (Builder $query) => $query->where('audience', self::AUDIENCE_TEAM)->where('team_id', $teamId));
            if (filled($plan)) {
                $query->orWhere(fn (Builder $query) => $query->where('audience', self::AUDIENCE_PLAN)->where('plan', $plan));
            }
        });
    }

    public function isAnnouncement(): bool
    {
        return in_array($this->kind, self::ANNOUNCEMENT_KINDS, true);
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_NEWS => __('News'),
            self::KIND_PROMOTION => __('Promotion'),
            self::KIND_UPDATE => __('Update'),
            self::KIND_MAINTENANCE => __('Maintenance'),
            self::KIND_ALERT => __('Important'),
            self::KIND_BILLING => __('Billing'),
            default => __('Account'),
        };
    }
}
