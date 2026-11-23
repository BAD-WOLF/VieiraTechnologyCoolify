<?php

namespace App\Services\Platform;

use App\Events\PlatformNotificationPublished;
use App\Models\PlatformNotification;
use App\Models\PlatformNotificationRead;
use App\Models\PlatformTenant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The bell of the dashboard: what a user sees with the team in use, what is still unread, and the
 * two ways notifications get there (an announcement of the owner, an event of an account).
 *
 * Announcements published before a user joined are listed but never count as unread: a new
 * customer does not open the dashboard to a pile of old news.
 */
final class PlatformInbox
{
    /** Notifications listed in the bell, newest first. */
    public const LIMIT = 30;

    /**
     * @return Collection<int, PlatformNotification> each with "unread"
     */
    public static function for(User $user, ?Team $team): Collection
    {
        $notifications = self::visible($user, $team)->latest('published_at')->latest('id')->limit(self::LIMIT)->get();
        $read = PlatformNotificationRead::query()->where('user_id', $user->id)
            ->whereIn('platform_notification_id', $notifications->pluck('id'))->pluck('platform_notification_id')->all();

        return $notifications->each(function (PlatformNotification $notification) use ($read, $user) {
            $notification->setAttribute('unread', ! in_array($notification->id, $read, true) && self::countsForUser($notification, $user));
        });
    }

    public static function unreadCount(User $user, ?Team $team): int
    {
        return self::unread($user, $team)->count();
    }

    public static function find(User $user, ?Team $team, string $uuid): ?PlatformNotification
    {
        return self::visible($user, $team)->where('uuid', $uuid)->first();
    }

    public static function markRead(User $user, PlatformNotification $notification): void
    {
        PlatformNotificationRead::query()->insertOrIgnore([
            'platform_notification_id' => $notification->id,
            'user_id' => $user->id,
            'read_at' => now(),
        ]);
    }

    public static function markAllRead(User $user, ?Team $team): int
    {
        $ids = self::unread($user, $team)->pluck('id');
        PlatformNotificationRead::query()->insertOrIgnore($ids->map(fn (int $id) => [
            'platform_notification_id' => $id,
            'user_id' => $user->id,
            'read_at' => now(),
        ])->all());

        return $ids->count();
    }

    /**
     * An announcement of the owner, already validated by the console.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function announce(array $attributes, ?User $author = null): PlatformNotification
    {
        $notification = PlatformNotification::query()->create(array_merge($attributes, ['created_by' => $author?->id]));
        audit_log('platform.notification.published', $notification, [
            'kind' => $notification->kind, 'title' => $notification->title, 'audience' => $notification->audience,
        ], 0);
        self::broadcast($notification);

        return $notification;
    }

    /**
     * An event of an account (invoice, limit, suspension), for every member of its team.
     */
    public static function toTeam(int $teamId, string $kind, string $title, ?string $body = null, ?string $actionUrl = null, ?string $actionLabel = null): ?PlatformNotification
    {
        if (! isPlatform() || $teamId === 0 || ! Team::query()->whereKey($teamId)->exists()) {
            return null;
        }
        try {
            $create = fn () => PlatformNotification::query()->create([
                'kind' => $kind,
                'title' => str($title)->limit(157)->value(),
                'body' => filled($body) ? str($body)->limit(4000)->value() : null,
                'action_url' => $actionUrl,
                'action_label' => filled($actionUrl) ? $actionLabel : null,
                'audience' => PlatformNotification::AUDIENCE_TEAM,
                'team_id' => $teamId,
            ]);
            // Inside a transaction a failed insert aborts it (PostgreSQL): a savepoint keeps the event going.
            $connection = (new PlatformNotification)->getConnection();
            $notification = $connection->transactionLevel() > 0 ? $connection->transaction($create) : $create();
            self::broadcast($notification);

            return $notification;
        } catch (\Throwable $e) {
            // The e-mail of the event still goes: the bell must not break what it reports.
            report($e);

            return null;
        }
    }

    /**
     * Users an announcement reaches, shown before it is sent.
     */
    public static function reach(string $audience, ?string $plan = null, ?int $teamId = null, ?string $kind = null): int
    {
        // Promotions and news reach only who consented to them (LGPD, art. 7º, I).
        $users = fn () => in_array($kind, Privacy::MARKETING_KINDS, true) ? Privacy::consenting(User::query()) : User::query();
        $members = fn (\Closure $teams) => $users()->whereHas('teams', $teams)->count();

        return match ($audience) {
            PlatformNotification::AUDIENCE_ALL => $users()->count(),
            PlatformNotification::AUDIENCE_CUSTOMERS => $members(fn (Builder $query) => $query->where('teams.id', '!=', 0)),
            PlatformNotification::AUDIENCE_OPERATORS => $members(fn (Builder $query) => $query->where('teams.id', 0)),
            PlatformNotification::AUDIENCE_PLAN => $members(fn (Builder $query) => $query->where('teams.id', '!=', 0)
                ->whereIn('teams.id', PlatformTenant::query()->where('plan', (string) $plan)->select('team_id'))),
            PlatformNotification::AUDIENCE_TEAM => is_null($teamId) || $teamId === 0 ? 0 : $members(fn (Builder $query) => $query->where('teams.id', $teamId)),
            default => 0,
        };
    }

    private static function visible(User $user, ?Team $team): Builder
    {
        $teamId = $team?->id;
        $plan = is_null($teamId) || $teamId === 0 ? null : PlatformTenant::query()->where('team_id', $teamId)->value('plan');

        return PlatformNotification::query()->live()->visibleTo($teamId, $plan)
            ->when(! Privacy::wantsMarketing($user), fn (Builder $query) => $query->whereNotIn('kind', Privacy::MARKETING_KINDS));
    }

    private static function unread(User $user, ?Team $team): Builder
    {
        return self::visible($user, $team)
            ->where(fn (Builder $query) => $query->where('audience', PlatformNotification::AUDIENCE_TEAM)
                ->orWhere('published_at', '>=', $user->created_at ?? now()))
            ->whereDoesntHave('reads', fn (Builder $query) => $query->where('user_id', $user->id));
    }

    private static function countsForUser(PlatformNotification $notification, User $user): bool
    {
        return $notification->audience === PlatformNotification::AUDIENCE_TEAM
            || is_null($user->created_at) || $notification->published_at->greaterThanOrEqualTo($user->created_at);
    }

    private static function broadcast(PlatformNotification $notification): void
    {
        if ($notification->published_at->isFuture()) {
            return;
        }
        try {
            PlatformNotificationPublished::dispatch($notification->audience === PlatformNotification::AUDIENCE_TEAM ? $notification->team_id : null);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
