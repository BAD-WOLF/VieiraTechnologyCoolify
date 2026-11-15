<?php

namespace App\Providers;

use App\Actions\Platform\ProvisionTenant;
use App\Exceptions\PlatformPolicyViolation;
use App\Http\Middleware\PlatformOperatorApiAllowlist;
use App\Http\Middleware\PlatformRequireTwoFactor;
use App\Jobs\PlatformBillingCycleJob;
use App\Jobs\PlatformCollectUsageJob;
use App\Jobs\PlatformDailyMaintenanceJob;
use App\Jobs\PlatformEnforcementJob;
use App\Jobs\PlatformRollupUsageJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ApplicationSetting;
use App\Models\EnvironmentVariable;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PersonalAccessToken;
use App\Models\PlatformRegion;
use App\Models\PlatformSetting;
use App\Models\PlatformTenant;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Platform\DestinationPolicy;
use App\Services\Platform\DomainPolicy;
use App\Services\Platform\PlatformBranding;
use App\Services\Platform\PlatformEvents;
use App\Services\Platform\PlatformGate;
use App\Services\Platform\TenantInputGuard;
use App\Services\Platform\TenantLimits;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the multi-tenant platform rules into the models, so they apply to every entry point
 * (Livewire UI, REST API, webhooks, parsers and jobs) without relying on each caller.
 */
class PlatformServiceProvider extends ServiceProvider
{
    /**
     * Resource models that run containers on a destination.
     *
     * @return array<int, class-string<Model>>
     */
    public static function workloadModels(): array
    {
        return array_merge([Application::class, Service::class], PlatformGate::databaseModels());
    }

    public function register(): void
    {
        // APP_PREVIOUS_KEYS: values encrypted with an older APP_KEY keep working during a rotation.
        if (blank(config('app.previous_keys')) && filled(config('platform.encryption.previous_keys'))) {
            config(['app.previous_keys' => config('platform.encryption.previous_keys')]);
        }
    }

    public function boot(): void
    {
        self::alignSubscriptionProvider();
        $this->app['router']->pushMiddlewareToGroup('web', PlatformRequireTwoFactor::class);
        $this->app['router']->pushMiddlewareToGroup('api', PlatformOperatorApiAllowlist::class);
        RateLimiter::for('platform-otlp', fn (Request $request) => Limit::perMinute(12000)->by($request->ip()));
        $this->registerRoutes();
        $this->registerRelations();
        $this->registerSchedule();
        $this->registerGates();
        $this->registerBranding();
        // Queue workers are long-lived: settings saved in the console must be read again per job.
        Queue::before(fn () => PlatformSetting::flushMemo());
        $this->loadJsonTranslationsFrom(lang_path('platform'));
        $this->registerWorkloadGuards();
        $this->registerInputGuards();
        $this->registerDomainGuards();
        $this->registerFeatureGuards();
        $this->registerServerGuards();
        $this->registerAuditTrail();
        $this->registerKeyFileSync();
        PlatformEvents::register();
    }

    /**
     * Coolify writes the SSH key file of a private key only when the file is missing, and the API
     * (PATCH /security/keys) does not rewrite it. After a key rotation every instance of the control
     * plane would keep connecting with the old key, so the file is refreshed whenever it no longer
     * matches the key stored in the database.
     */
    private function registerKeyFileSync(): void
    {
        PrivateKey::retrieved(function (PrivateKey $key): void {
            // Only full models: a query that did not select the key must not touch the file.
            $attributes = $key->getAttributes();
            if (! isPlatform() || blank($attributes['private_key'] ?? null) || blank($attributes['uuid'] ?? null)) {
                return;
            }
            try {
                $disk = Storage::disk('ssh-keys');
                $file = "ssh_key@{$key->uuid}";
                $privateKey = $key->private_key;
                if (is_string($privateKey) && $privateKey !== '' && $disk->exists($file) && $disk->get($file) !== $privateKey) {
                    $key->storeInFileSystem();
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Coolify (cloud mode) opens the dashboard only to teams whose subscription record is paid, and
     * reads that record only when the subscription provider is "stripe". The platform keeps the
     * record in sync with its own billing (Banco Inter or Stripe), so the check must read it
     * whatever the provider; otherwise every paying tenant is sent back to /subscription/new.
     */
    public static function alignSubscriptionProvider(): void
    {
        if (isPlatform() && blank(config('subscription.provider'))) {
            config(['subscription.provider' => 'stripe']);
        }
    }

    /**
     * The platform routes live in routes/platform/*.php. This provider boots before the
     * RouteServiceProvider, so they are registered ahead of the upstream catch-all routes.
     */
    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('api')->prefix('api')->group(base_path('routes/platform/api.php'));
        Route::middleware('web')->group(base_path('routes/platform/web.php'));
    }

    /**
     * Platform relations of upstream models, registered here instead of editing the models.
     */
    private function registerRelations(): void
    {
        Server::resolveRelationUsing('platformRegion', fn (Server $server) => $server->belongsTo(PlatformRegion::class, 'platform_region_id'));
        Team::resolveRelationUsing('platformTenant', fn (Team $team) => $team->hasOne(PlatformTenant::class));
        StandaloneDocker::resolveRelationUsing('team', fn (StandaloneDocker $destination) => $destination->belongsTo(Team::class));
    }

    /**
     * Metering, rollups, enforcement and billing reconciliation (added to the Coolify schedule).
     */
    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (isPlatform()) {
                $this->schedulePlatformJobs($schedule);
            }
            if (config('platform.control_plane.cluster')) {
                self::runScheduleOnOneServer($schedule);
            }
        });
    }

    /**
     * Control plane cluster (wiki 6, active/active): every instance runs the scheduler and each
     * task takes a cache lock, so it runs on one instance only and keeps running if one fails.
     */
    public static function runScheduleOnOneServer(Schedule $schedule): void
    {
        foreach ($schedule->events() as $index => $event) {
            if (blank($event->description)) {
                $event->description = 'hostrema-scheduled-task-'.$index;
            }
            $event->onOneServer();
        }
    }

    private function schedulePlatformJobs(Schedule $schedule): void
    {
        $interval = max(1, min(30, (int) config('platform.metering.interval_minutes', 5)));
        $schedule->job(new PlatformCollectUsageJob)->cron("*/{$interval} * * * *")->onOneServer();
        $schedule->job(new PlatformRollupUsageJob)->hourlyAt(7)->onOneServer();
        $schedule->job(new PlatformEnforcementJob)->everyTenMinutes()->onOneServer();
        $schedule->job(new PlatformDailyMaintenanceJob)->dailyAt('03:17')->onOneServer();
        $schedule->job(new PlatformBillingCycleJob)->everyFifteenMinutes()->onOneServer();
    }

    /**
     * The terminal server trusts the SSH command sent by the browser, so on shared nodes tenants
     * only get the terminal when it is explicitly enabled (config platform.tenant_terminal).
     */
    private function registerGates(): void
    {
        Gate::before(function ($user, string $ability) {
            if ($ability === 'canAccessTerminal' && isPlatformTenant() && ! config('platform.tenant_terminal')) {
                return false;
            }

            return null;
        });
    }

    /**
     * Platform name instead of "Coolify" in the dashboard and in e-mail subjects.
     */
    private function registerBranding(): void
    {
        Blade::precompiler(fn (string $template) => PlatformBranding::enabled() ? PlatformBranding::apply($template) : $template);
        Event::listen(MessageSending::class, function (MessageSending $event) {
            $subject = $event->message->getSubject();
            if (PlatformBranding::enabled() && filled($subject)) {
                $event->message->subject(PlatformBranding::text($subject));
            }
        });
    }

    private function registerWorkloadGuards(): void
    {
        foreach (self::workloadModels() as $model) {
            $model::creating(function (Model $resource) {
                PlatformGate::assertCanCreateResource($resource);
            });
            $model::saving(function (Model $resource) {
                if (! $resource->exists || $resource->isDirty(['destination_id', 'destination_type', 'environment_id'])) {
                    DestinationPolicy::assertResourceDestination($resource);
                }
            });
            $model::deleted(function (Model $resource) {
                PlatformGate::release($resource);
            });
        }
    }

    private function registerInputGuards(): void
    {
        $models = array_merge(self::workloadModels(), [
            ApplicationSetting::class,
            ServiceApplication::class,
            ServiceDatabase::class,
            ScheduledTask::class,
            ScheduledDatabaseBackup::class,
            LocalFileVolume::class,
            LocalPersistentVolume::class,
            S3Storage::class,
            EnvironmentVariable::class,
        ]);
        foreach ($models as $model) {
            $model::saving(function (Model $instance) {
                if (! isPlatform()) {
                    return;
                }
                $team = platformTeamOf($instance);
                if (is_null($team) || ! isPlatformTenant($team)) {
                    return;
                }
                TenantInputGuard::validate($instance);
            });
        }
    }

    private function registerDomainGuards(): void
    {
        $guard = function (Model $model, array $fields) {
            if (! isPlatform() || ($model->exists && ! $model->isDirty($fields))) {
                return;
            }
            $team = platformTeamOf($model);
            if (is_null($team) || ! isPlatformTenant($team)) {
                return;
            }
            $hosts = array_values(array_unique(array_merge(
                DomainPolicy::hostsFromFqdnList((string) $model->getAttribute('fqdn')),
                in_array('docker_compose_domains', $fields, true) ? DomainPolicy::hostsFromComposeDomains($model->getAttribute('docker_compose_domains')) : [],
            )));
            if (count($hosts) === 0) {
                return;
            }
            DomainPolicy::assertAvailable($hosts, $team->id);
            $limit = (int) PlatformTenant::forTeam($team)->quota('custom_domains');
            if ($limit > 0) {
                $previous = array_merge(
                    DomainPolicy::hostsFromFqdnList((string) $model->getOriginal('fqdn')),
                    in_array('docker_compose_domains', $fields, true) ? DomainPolicy::hostsFromComposeDomains($model->getOriginal('docker_compose_domains')) : [],
                );
                $all = array_unique(array_merge(array_diff(DomainPolicy::hostsOwnedBy($team), $previous), $hosts));
                $custom = count(array_filter($all, fn ($host) => DomainPolicy::isCustomHost($host)));
                if ($custom > $limit) {
                    throw PlatformPolicyViolation::single("Your plan allows {$limit} custom domains.", 'Plan limit reached');
                }
            }
        };

        Application::saving(fn (Application $application) => $guard($application, ['fqdn', 'docker_compose_domains']));
        ApplicationPreview::saving(fn (ApplicationPreview $preview) => $guard($preview, ['fqdn', 'docker_compose_domains']));
        ServiceApplication::saving(fn (ServiceApplication $application) => $guard($application, ['fqdn']));
    }

    private function registerFeatureGuards(): void
    {
        $requireFeature = function (Model $model, string $feature, string $message) {
            if (! isPlatform()) {
                return;
            }
            $team = platformTeamOf($model);
            if (is_null($team) || ! isPlatformTenant($team)) {
                return;
            }
            if (! PlatformTenant::forTeam($team)->hasFeature($feature)) {
                throw PlatformPolicyViolation::single($message, 'Not included in your plan');
            }
        };

        LocalPersistentVolume::creating(function (LocalPersistentVolume $volume) use ($requireFeature) {
            if (in_array($volume->resource_type, PlatformGate::databaseModels(), true) || $volume->resource_type === ServiceDatabase::class) {
                return;
            }
            $requireFeature($volume, 'persistent_volumes', 'Persistent volumes are available from the Professional plan.');
        });
        LocalFileVolume::creating(function (LocalFileVolume $volume) use ($requireFeature) {
            if ($volume->is_directory && $volume->resource_type === Application::class) {
                $requireFeature($volume, 'persistent_volumes', 'Directory mounts are available from the Professional plan.');
            }
        });
        ScheduledDatabaseBackup::creating(fn (ScheduledDatabaseBackup $backup) => $requireFeature($backup, 'scheduled_backups', 'Scheduled backups are available from the Professional plan.'));
        ApplicationPreview::creating(fn (ApplicationPreview $preview) => $requireFeature($preview, 'preview_deployments', 'Preview deployments are available from the Professional plan.'));
        foreach (array_merge(PlatformGate::databaseModels(), [ServiceDatabase::class]) as $database) {
            $database::saving(function (Model $database) use ($requireFeature) {
                if ($database->isDirty('is_public') && $database->is_public) {
                    $requireFeature($database, 'public_databases', 'Public databases are available from the Professional plan.');
                }
                if ($database->isDirty('is_log_drain_enabled') && $database->is_log_drain_enabled) {
                    $requireFeature($database, 'log_export', 'Log export is available from the Professional plan.');
                }
            });
        }
        ApplicationSetting::saving(function (ApplicationSetting $setting) use ($requireFeature) {
            if ($setting->isDirty('is_log_drain_enabled') && $setting->is_log_drain_enabled) {
                $requireFeature($setting, 'log_export', 'Log export is available from the Professional plan.');
            }
            if ($setting->isDirty('is_preview_deployments_enabled') && $setting->is_preview_deployments_enabled) {
                $requireFeature($setting, 'preview_deployments', 'Preview deployments are available from the Professional plan.');
            }
        });
        ServiceApplication::saving(function (ServiceApplication $application) use ($requireFeature) {
            if ($application->isDirty('is_log_drain_enabled') && $application->is_log_drain_enabled) {
                $requireFeature($application, 'log_export', 'Log export is available from the Professional plan.');
            }
        });
    }

    private function registerServerGuards(): void
    {
        Server::creating(function (Server $server) {
            if (! isPlatform() || (int) $server->team_id === 0 || config('platform.allow_tenant_servers')) {
                return;
            }
            throw PlatformPolicyViolation::single('Servers are managed by the platform.', 'Not allowed');
        });
        Team::deleting(function (Team $team) {
            if (! isPlatform()) {
                return;
            }
            \App\Models\StandaloneDocker::query()->where('team_id', $team->id)->get()->each(fn ($destination) => $destination->delete());
        });
    }

    /**
     * The listeners never return a value: a listener that returns false stops the event, and the
     * model's own listeners (settings of a new application, data volume of a new database...)
     * would not run.
     */
    private function registerAuditTrail(): void
    {
        $audit = function (string $action, ?Model $subject, array $metadata = [], ?int $teamId = null, ?string $actorType = null): void {
            if (isPlatform() && ! PlatformEvents::isMuted()) {
                audit_log($action, $subject, $metadata, $teamId, $actorType);
            }
        };
        foreach (self::workloadModels() as $model) {
            $model::created(function (Model $resource) use ($audit): void {
                $audit('resource.created', $resource, ['name' => $resource->getAttribute('name')]);
            });
            $model::deleted(function (Model $resource) use ($audit): void {
                $audit('resource.deleted', $resource, ['name' => $resource->getAttribute('name')]);
            });
        }
        EnvironmentVariable::saved(function (EnvironmentVariable $variable) use ($audit): void {
            $audit($variable->wasRecentlyCreated ? 'env.created' : 'env.updated', $variable, ['key' => $variable->key]);
        });
        EnvironmentVariable::deleted(function (EnvironmentVariable $variable) use ($audit): void {
            $audit('env.deleted', $variable, ['key' => $variable->key]);
        });
        PersonalAccessToken::created(function (PersonalAccessToken $token) use ($audit): void {
            $audit('api_token.created', $token, ['name' => $token->name, 'abilities' => $token->abilities], $token->team_id ?? null);
        });
        PersonalAccessToken::deleted(function (PersonalAccessToken $token) use ($audit): void {
            $audit('api_token.deleted', $token, ['name' => $token->name], $token->team_id ?? null);
        });
        TeamInvitation::created(function (TeamInvitation $invitation) use ($audit): void {
            $audit('team.invitation.created', $invitation, ['email' => $invitation->email, 'role' => $invitation->role], $invitation->team_id);
        });
        PlatformTenant::updated(function (PlatformTenant $tenant) {
            // New quotas: the slices and bandwidth of the tenant are updated on every node.
            if (isPlatform() && TenantLimits::enabled() && $tenant->provisioned_at && $tenant->wasChanged(['plan', 'quota_overrides'])) {
                ProvisionTenant::dispatch($tenant->team_id);
            }
            if ($tenant->wasChanged(['status', 'plan'])) {
                audit_log('tenant.updated', $tenant, [
                    'status' => [$tenant->getOriginal('status'), $tenant->status],
                    'plan' => [$tenant->getOriginal('plan'), $tenant->plan],
                    'reason' => $tenant->suspension_reason,
                ], $tenant->team_id);
            }
        });
        Event::listen(Login::class, function (Login $event) use ($audit): void {
            $audit('auth.login', $event->user, [], null, 'user');
        });
        Event::listen(Failed::class, function (Failed $event) use ($audit): void {
            $audit('auth.failed', null, ['email' => data_get($event->credentials, 'email')], null, 'anonymous');
        });
    }
}
