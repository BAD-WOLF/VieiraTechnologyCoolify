<?php

namespace App\Services\Platform;

use App\Events\PlatformEventOccurred;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PlatformInvoice;
use App\Models\PlatformTenant;
use App\Models\PlatformWebhook;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Providers\PlatformServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Event hooks of the platform (wiki 9, "Falta de Plugins e Hooks Públicos" and "Limitações de API
 * Interna"). Every event is:
 *  1. a Laravel event (PlatformEventOccurred) for extensions inside the fork;
 *  2. an entry of the Redis stream "hostrema:events" for internal consumers (XREAD / XREADGROUP);
 *  3. a signed HTTP delivery to the webhooks of the team (and to operator webhooks with all_teams).
 * Recording an event never breaks the action that caused it.
 */
class PlatformEvents
{
    /** @var array<string, string> */
    public const TYPES = [
        'deployment.queued' => 'A deployment was queued.',
        'deployment.started' => 'A deployment started.',
        'deployment.finished' => 'A deployment finished successfully.',
        'deployment.failed' => 'A deployment failed.',
        'deployment.cancelled' => 'A deployment was cancelled.',
        'resource.created' => 'An application, service or database was created.',
        'resource.deleted' => 'An application, service or database was deleted.',
        'resource.status_changed' => 'The containers of a resource changed state (started, stopped, unhealthy...).',
        'backup.succeeded' => 'A database backup finished.',
        'backup.failed' => 'A database backup failed.',
        'tenant.plan_changed' => 'The plan of the team changed.',
        'tenant.status_changed' => 'The account status changed (active, limited, suspended...).',
        'invoice.created' => 'An invoice was issued.',
        'invoice.paid' => 'An invoice was paid.',
        'invoice.status_changed' => 'An invoice changed status (cancelled, refunded...).',
        'webhook.test' => 'Test event sent from the dashboard or the API.',
    ];

    /** @var array<string, string> deployment status => event */
    private const DEPLOYMENT_EVENTS = [
        'queued' => 'deployment.queued',
        'in_progress' => 'deployment.started',
        'finished' => 'deployment.finished',
        'failed' => 'deployment.failed',
        'cancelled-by-user' => 'deployment.cancelled',
    ];

    private static bool $muted = false;

    /**
     * Run without platform events and audit entries (repairs that replay model events).
     */
    public static function muted(callable $callback): mixed
    {
        $previous = self::$muted;
        self::$muted = true;
        try {
            return $callback();
        } finally {
            self::$muted = $previous;
        }
    }

    public static function isMuted(): bool
    {
        return self::$muted;
    }

    public static function register(): void
    {
        ApplicationDeploymentQueue::saved(function (ApplicationDeploymentQueue $deployment) {
            if ($deployment->wasRecentlyCreated || $deployment->wasChanged('status')) {
                self::safely(fn () => self::deployment($deployment));
            }
        });
        foreach (PlatformServiceProvider::workloadModels() as $model) {
            $model::created(function (Model $resource): void {
                self::safely(fn () => self::dispatch('resource.created', self::teamOf($resource), ['resource' => self::describe($resource)]));
            });
            $model::deleted(function (Model $resource): void {
                self::safely(fn () => self::dispatch('resource.deleted', self::teamOf($resource), ['resource' => self::describe($resource)]));
            });
            $model::updated(function (Model $resource) {
                if ($resource->wasChanged('status')) {
                    self::safely(fn () => self::statusChanged($resource, $resource));
                }
            });
        }
        foreach ([ServiceApplication::class, ServiceDatabase::class] as $component) {
            $component::updated(function (Model $model) {
                if ($model->wasChanged('status') && $model->getAttribute('service') instanceof Service) {
                    self::safely(fn () => self::statusChanged($model->getAttribute('service'), $model));
                }
            });
        }
        ScheduledDatabaseBackupExecution::saved(function (ScheduledDatabaseBackupExecution $execution) {
            if ($execution->wasRecentlyCreated || $execution->wasChanged('status')) {
                self::safely(fn () => self::backup($execution));
            }
        });
        PlatformTenant::updated(function (PlatformTenant $tenant) {
            self::safely(function () use ($tenant) {
                if ($tenant->wasChanged('plan')) {
                    self::dispatch('tenant.plan_changed', (int) $tenant->team_id, ['plan' => $tenant->plan, 'previous_plan' => $tenant->getOriginal('plan')]);
                }
                if ($tenant->wasChanged('status')) {
                    self::dispatch('tenant.status_changed', (int) $tenant->team_id, [
                        'status' => $tenant->status,
                        'previous_status' => $tenant->getOriginal('status'),
                        'reason' => $tenant->getAttribute('suspension_reason'),
                    ]);
                }
            });
        });
        PlatformInvoice::created(function (PlatformInvoice $invoice): void {
            self::safely(fn () => self::dispatch('invoice.created', (int) $invoice->team_id, self::invoice($invoice)));
        });
        PlatformInvoice::updated(function (PlatformInvoice $invoice) {
            if ($invoice->wasChanged('status') && $invoice->getOriginal('status') !== null) {
                $type = $invoice->status === PlatformInvoice::STATUS_PAID ? 'invoice.paid' : 'invoice.status_changed';
                self::safely(fn () => self::dispatch($type, (int) $invoice->team_id, self::invoice($invoice) + ['previous_status' => $invoice->getOriginal('status')]));
            }
        });
    }

    /**
     * Record an event: Laravel event, Redis stream and webhooks.
     *
     * @param  array<string, mixed>  $data
     */
    public static function dispatch(string $type, ?int $teamId, array $data = []): ?PlatformEventOccurred
    {
        if (! isPlatform() || self::$muted) {
            return null;
        }
        $event = new PlatformEventOccurred('evt_'.strtolower((string) Str::ulid()), $type, $teamId, $data, now()->utc()->format('Y-m-d\TH:i:s.v\Z'));
        event($event);
        self::safely(fn () => self::publishToStream($event));
        self::safely(fn () => Webhooks::queue($event));

        return $event;
    }

    /**
     * Webhook ids per team, and the operator webhooks that receive every team.
     *
     * @return array{teams: array<int, list<int>>, all: list<int>}
     */
    public static function subscribers(): array
    {
        return Cache::remember('platform-webhooks:subscribers', 60, function () {
            $subscribers = ['teams' => [], 'all' => []];
            PlatformWebhook::query()->where('enabled', true)->get(['id', 'team_id', 'all_teams'])->each(function (PlatformWebhook $webhook) use (&$subscribers) {
                if ($webhook->all_teams && (int) $webhook->team_id === 0) {
                    $subscribers['all'][] = $webhook->id;
                } else {
                    $subscribers['teams'][(int) $webhook->team_id][] = $webhook->id;
                }
            });

            return $subscribers;
        });
    }

    public static function streamKey(): string
    {
        return (string) config('platform.events.stream', 'hostrema:events');
    }

    /**
     * @return array{uuid: ?string, name: ?string, type: string}
     */
    public static function describe(Model $resource): array
    {
        return [
            'uuid' => $resource->getAttribute('uuid'),
            'name' => $resource->getAttribute('name'),
            'type' => match (true) {
                $resource instanceof Application => 'application',
                $resource instanceof Service => 'service',
                default => str(class_basename($resource))->after('Standalone')->lower()->value(),
            },
        ];
    }

    private static function deployment(ApplicationDeploymentQueue $deployment): void
    {
        $type = self::DEPLOYMENT_EVENTS[(string) $deployment->status] ?? null;
        $application = $deployment->application;
        if (is_null($type) || ! $application instanceof Application) {
            return;
        }
        self::dispatch($type, self::teamOf($application), [
            'deployment_uuid' => $deployment->deployment_uuid,
            'status' => $deployment->status,
            'application' => self::describe($application),
            'commit' => $deployment->commit,
            'pull_request_id' => (int) $deployment->pull_request_id,
            'rollback' => (bool) $deployment->rollback,
            'started_by_api' => (bool) $deployment->is_api,
            'started_by_webhook' => (bool) $deployment->is_webhook,
        ]);
    }

    private static function statusChanged(Model $resource, Model $changed): void
    {
        $data = [
            'resource' => self::describe($resource),
            'status' => $changed->getAttribute('status'),
            'previous_status' => $changed->getOriginal('status'),
        ];
        if ($changed !== $resource) {
            $data['component'] = $changed->getAttribute('name');
        }
        self::dispatch('resource.status_changed', self::teamOf($resource), $data);
    }

    private static function backup(ScheduledDatabaseBackupExecution $execution): void
    {
        $type = match ((string) $execution->status) {
            'success' => 'backup.succeeded',
            'failed' => 'backup.failed',
            default => null,
        };
        $schedule = $execution->scheduledDatabaseBackup;
        if (is_null($type) || is_null($schedule)) {
            return;
        }
        $database = $schedule->database;
        self::dispatch($type, (int) $schedule->team_id, [
            'backup_uuid' => $schedule->uuid,
            'database' => $database instanceof Model ? self::describe($database) : null,
            'database_name' => $execution->database_name,
            'size_bytes' => (int) $execution->size,
            'message' => $type === 'backup.failed' ? str((string) $execution->message)->limit(500)->value() : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoice(PlatformInvoice $invoice): array
    {
        return [
            'invoice_uuid' => $invoice->uuid,
            'type' => $invoice->type,
            'status' => $invoice->status,
            'amount_cents' => (int) $invoice->amount_cents,
            'currency' => $invoice->currency,
            'period_start' => $invoice->period_start?->toIso8601String(),
            'period_end' => $invoice->period_end?->toIso8601String(),
            'number' => $invoice->getAttribute('number'),
            'due_date' => $invoice->due_date?->toDateString(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ];
    }

    private static function teamOf(Model $resource): ?int
    {
        $teamId = data_get($resource, 'environment.project.team_id');

        return is_null($teamId) ? null : (int) $teamId;
    }

    private static function publishToStream(PlatformEventOccurred $event): void
    {
        $key = self::streamKey();
        if ($key === '') {
            return;
        }
        $arguments = [
            $key, 'MAXLEN', '~', (string) max(1000, (int) config('platform.events.stream_max_length', 100000)), '*',
            'id', $event->id,
            'type', $event->type,
            'team_id', (string) ($event->teamId ?? ''),
            'created_at', $event->occurredAt,
            'data', json_encode($event->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        // Raw command: the key has no Laravel prefix, so external consumers read "hostrema:events".
        $client = Redis::connection()->client();
        if ($client instanceof \Redis) {
            $client->rawCommand('XADD', ...$arguments);
        } elseif (method_exists($client, 'executeRaw')) {
            $client->executeRaw(array_merge(['XADD'], $arguments));
        }
    }

    private static function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
