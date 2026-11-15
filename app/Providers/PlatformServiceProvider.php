<?php

namespace App\Providers;

use App\Exceptions\PlatformPolicyViolation;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ApplicationSetting;
use App\Models\EnvironmentVariable;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PersonalAccessToken;
use App\Models\PlatformTenant;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Platform\DestinationPolicy;
use App\Services\Platform\DomainPolicy;
use App\Services\Platform\PlatformGate;
use App\Services\Platform\TenantInputGuard;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
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

    public function boot(): void
    {
        $this->registerWorkloadGuards();
        $this->registerInputGuards();
        $this->registerDomainGuards();
        $this->registerFeatureGuards();
        $this->registerServerGuards();
        $this->registerAuditTrail();
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

    private function registerAuditTrail(): void
    {
        foreach (self::workloadModels() as $model) {
            $model::created(fn (Model $resource) => isPlatform() && audit_log('resource.created', $resource, ['name' => $resource->getAttribute('name')]));
            $model::deleted(fn (Model $resource) => isPlatform() && audit_log('resource.deleted', $resource, ['name' => $resource->getAttribute('name')]));
        }
        EnvironmentVariable::saved(fn (EnvironmentVariable $variable) => isPlatform() && audit_log($variable->wasRecentlyCreated ? 'env.created' : 'env.updated', $variable, ['key' => $variable->key]));
        EnvironmentVariable::deleted(fn (EnvironmentVariable $variable) => isPlatform() && audit_log('env.deleted', $variable, ['key' => $variable->key]));
        PersonalAccessToken::created(fn (PersonalAccessToken $token) => isPlatform() && audit_log('api_token.created', $token, ['name' => $token->name, 'abilities' => $token->abilities], $token->team_id ?? null));
        PersonalAccessToken::deleted(fn (PersonalAccessToken $token) => isPlatform() && audit_log('api_token.deleted', $token, ['name' => $token->name], $token->team_id ?? null));
        TeamInvitation::created(fn (TeamInvitation $invitation) => isPlatform() && audit_log('team.invitation.created', $invitation, ['email' => $invitation->email, 'role' => $invitation->role], $invitation->team_id));
        PlatformTenant::updated(function (PlatformTenant $tenant) {
            if ($tenant->wasChanged(['status', 'plan'])) {
                audit_log('tenant.updated', $tenant, [
                    'status' => [$tenant->getOriginal('status'), $tenant->status],
                    'plan' => [$tenant->getOriginal('plan'), $tenant->plan],
                    'reason' => $tenant->suspension_reason,
                ], $tenant->team_id);
            }
        });
        Event::listen(Login::class, fn (Login $event) => isPlatform() && audit_log('auth.login', $event->user, [], null, 'user'));
        Event::listen(Failed::class, fn (Failed $event) => isPlatform() && audit_log('auth.failed', null, ['email' => data_get($event->credentials, 'email')], null, 'anonymous'));
    }
}
