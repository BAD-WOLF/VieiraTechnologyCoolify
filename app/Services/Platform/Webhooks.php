<?php

namespace App\Services\Platform;

use App\Events\PlatformEventOccurred;
use App\Jobs\PlatformWebhookDeliveryJob;
use App\Models\PlatformWebhook;
use App\Models\PlatformWebhookDelivery;
use Illuminate\Support\Str;

/**
 * Webhooks of the teams: registration rules and queueing of the deliveries.
 */
class Webhooks
{
    /** @var array<string, list<string>> option => event patterns */
    public const GROUPS = [
        'all' => ['*'],
        'deployments' => ['deployment.*'],
        'resources' => ['resource.*'],
        'backups' => ['backup.*'],
        'billing' => ['tenant.*', 'invoice.*'],
    ];

    /**
     * @param  list<string>  $events  event types, patterns (deployment.*) or "*"
     */
    public static function create(int $teamId, string $url, array $events, ?string $description = null, bool $allTeams = false): PlatformWebhook
    {
        $url = trim($url);
        $error = WebhookUrlGuard::validate($url, self::allowsPrivateTargets($teamId));
        if ($error) {
            throw new \InvalidArgumentException($error);
        }
        $events = self::normalizeEvents($events);
        $limit = (int) config('platform.webhooks.max_per_team', 10);
        if (PlatformWebhook::query()->where('team_id', $teamId)->count() >= $limit) {
            throw new \InvalidArgumentException(__('A team can have at most :max webhooks.', ['max' => $limit]));
        }
        $webhook = PlatformWebhook::create([
            'team_id' => $teamId,
            'url' => $url,
            'events' => $events,
            'description' => filled($description) ? Str::limit(strip_tags($description), 255, '') : null,
            'all_teams' => $allTeams && $teamId === 0,
        ]);
        audit_log('webhook.created', $webhook, ['url' => self::displayUrl($url), 'events' => $events], $teamId);

        return $webhook;
    }

    /**
     * @param  list<string>  $events
     * @return list<string>
     */
    public static function normalizeEvents(array $events): array
    {
        $events = array_values(array_unique(array_filter(array_map(fn ($event) => strtolower(trim((string) $event)), $events))));
        if ($events === []) {
            throw new \InvalidArgumentException(__('Choose at least one event.'));
        }
        foreach ($events as $event) {
            if ($event === '*') {
                continue;
            }
            $known = str_ends_with($event, '.*')
                ? collect(array_keys(PlatformEvents::TYPES))->contains(fn ($type) => str_starts_with($type, substr($event, 0, -1)))
                : array_key_exists($event, PlatformEvents::TYPES);
            if (! $known) {
                throw new \InvalidArgumentException(__('Unknown event ":event".', ['event' => $event]));
            }
        }

        return in_array('*', $events, true) ? ['*'] : $events;
    }

    /**
     * Deliveries of one event to every webhook that listens to it.
     */
    public static function queue(PlatformEventOccurred $event): void
    {
        $subscribers = PlatformEvents::subscribers();
        $ids = array_merge(is_null($event->teamId) ? [] : ($subscribers['teams'][$event->teamId] ?? []), $subscribers['all']);
        if ($ids === []) {
            return;
        }
        PlatformWebhook::query()->whereIn('id', array_unique($ids))->where('enabled', true)->get()
            ->each(function (PlatformWebhook $webhook) use ($event) {
                $ownTeam = ! is_null($event->teamId) && (int) $webhook->team_id === $event->teamId;
                if (($ownTeam || ($webhook->all_teams && (int) $webhook->team_id === 0)) && $webhook->listensTo($event->type)) {
                    self::deliver($webhook, $event->payload());
                }
            });
    }

    /**
     * Test event for one webhook.
     */
    public static function sendTest(PlatformWebhook $webhook): PlatformWebhookDelivery
    {
        $event = new PlatformEventOccurred('evt_'.strtolower((string) Str::ulid()), 'webhook.test', (int) $webhook->team_id, [
            'webhook_uuid' => $webhook->uuid,
            'message' => 'Test event from '.config('platform.name', 'Hostrema').'.',
        ], now()->utc()->format('Y-m-d\TH:i:s.v\Z'));

        return self::deliver($webhook, $event->payload());
    }

    public static function resend(PlatformWebhookDelivery $delivery): PlatformWebhookDelivery
    {
        return self::deliver($delivery->webhook, (array) $delivery->payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function deliver(PlatformWebhook $webhook, array $payload): PlatformWebhookDelivery
    {
        $delivery = $webhook->deliveries()->create([
            'event_id' => (string) ($payload['id'] ?? ''),
            'event_type' => (string) ($payload['type'] ?? ''),
            'payload' => $payload,
            'status' => PlatformWebhookDelivery::STATUS_PENDING,
        ]);
        PlatformWebhookDeliveryJob::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    /**
     * Header "X-Hostrema-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">".
     */
    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function allowsPrivateTargets(int $teamId): bool
    {
        return $teamId === 0 && (bool) config('platform.webhooks.operator_private_targets', false);
    }

    public static function displayUrl(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }

    public static function prune(): int
    {
        $days = max(1, (int) config('platform.webhooks.retention_days', 14));

        return PlatformWebhookDelivery::query()->where('created_at', '<', now()->subDays($days))->delete();
    }
}
