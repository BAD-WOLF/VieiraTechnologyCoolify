<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ApplicationSetting;
use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PlatformTenant;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform (multi-tenant PaaS) mode is on: shared nodes owned by the root team, paid plans per tenant.
 */
function isPlatform(): bool
{
    return isCloud() && (bool) config('platform.enabled');
}

/**
 * The given team (or the current team) is a paying tenant of the platform, i.e. not the operator (root team).
 */
function isPlatformTenant(Team|int|null $team = null): bool
{
    if (! isPlatform()) {
        return false;
    }
    if (is_null($team)) {
        $team = currentTeam();
    }
    if (is_null($team)) {
        return false;
    }
    $teamId = $team instanceof Team ? $team->id : $team;

    return (int) $teamId !== 0;
}

/**
 * Pages a platform customer opens before the plan is active: the dashboard, home of the panel,
 * which shows how to activate the plan (creating and deploying stay blocked by the plan status).
 *
 * @return array<int, string>
 */
function platformPathsForUnsubscribedAccounts(): array
{
    return isPlatformTenant() && ! isSubscriptionActive() ? ['/'] : [];
}

function platformTenant(Team|int|null $team = null): ?PlatformTenant
{
    if (is_null($team)) {
        $team = currentTeam();
    }
    if (! isPlatformTenant($team)) {
        return null;
    }

    return PlatformTenant::forTeam($team);
}

/**
 * Resolve the team that owns a resource-like model (applications, databases, services and their children).
 *
 * Parents are looked up by plain column queries, never loaded as models: guards call this while a
 * model is being saved, and loading a parent fires its own events (StandaloneRedis creates its
 * REDIS_USERNAME variable when it is retrieved, which would save a variable inside the guard of a
 * variable, endlessly).
 */
function platformTeamOf(mixed $model): ?Team
{
    if (is_null($model)) {
        return null;
    }
    if ($model instanceof Team) {
        return $model;
    }

    $environmentOf = function (string $class, mixed $id): ?int {
        if (blank($id) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }
        if ($class === ServiceApplication::class || $class === ServiceDatabase::class) {
            $serviceId = $class::query()->whereKey($id)->value('service_id');

            return $serviceId ? Service::query()->whereKey($serviceId)->value('environment_id') : null;
        }
        if ($class === ApplicationSetting::class || $class === ApplicationPreview::class) {
            $applicationId = $class::query()->whereKey($id)->value('application_id');

            return $applicationId ? Application::query()->whereKey($applicationId)->value('environment_id') : null;
        }
        static $withEnvironment = [];
        $withEnvironment[$class] ??= \Illuminate\Support\Facades\Schema::hasColumn((new $class)->getTable(), 'environment_id');

        return $withEnvironment[$class] ? $class::query()->whereKey($id)->value('environment_id') : null;
    };

    $environmentId = match (true) {
        $model instanceof ServiceApplication, $model instanceof ServiceDatabase => $model->relationLoaded('service') && $model->service
            ? $model->service->environment_id
            : Service::query()->whereKey($model->service_id)->value('environment_id'),
        $model instanceof ApplicationSetting, $model instanceof ApplicationPreview => $model->relationLoaded('application') && $model->application
            ? $model->application->environment_id
            : Application::query()->whereKey($model->application_id)->value('environment_id'),
        $model instanceof LocalFileVolume, $model instanceof LocalPersistentVolume => $environmentOf((string) $model->resource_type, $model->resource_id),
        $model instanceof EnvironmentVariable => $environmentOf((string) $model->resourceable_type, $model->resourceable_id),
        $model instanceof ScheduledTask => $model->application_id
            ? Application::query()->whereKey($model->application_id)->value('environment_id')
            : Service::query()->whereKey($model->service_id)->value('environment_id'),
        $model instanceof ScheduledDatabaseBackup, $model instanceof S3Storage => false,
        $model instanceof Model && isset($model->environment_id) => $model->environment_id,
        default => null,
    };

    if ($environmentId === false) {
        return $model->team_id !== null ? Team::find($model->team_id) : null;
    }
    if (filled($environmentId)) {
        $teamId = Environment::query()->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('environments.id', $environmentId)->value('projects.team_id');

        return $teamId !== null ? Team::find($teamId) : null;
    }
    if ($model instanceof EnvironmentVariable || $model instanceof LocalFileVolume || $model instanceof LocalPersistentVolume) {
        return null;
    }
    if (method_exists($model, 'team')) {
        $team = $model->team();
        if ($team instanceof Team) {
            return $team;
        }
    }

    return null;
}

/**
 * Format an amount in cents of the platform currency (e.g. "R$ 49,90").
 */
function formatPlatformMoney(int|float $cents, ?string $currency = null): string
{
    $currency = strtoupper($currency ?: config('platform.currency', 'brl'));
    $amount = $cents / 100;
    if (class_exists(\NumberFormatter::class)) {
        $locale = str_replace('-', '_', (string) config('app.locale', 'en'));
        $formatter = new \NumberFormatter($currency === 'BRL' && $locale === 'en' ? 'pt_BR' : $locale, \NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency($amount, $currency);
        if ($formatted !== false) {
            return $formatted;
        }
    }

    return $currency.' '.number_format($amount, 2, ',', '.');
}

/**
 * Append an entry to the audit trail. Never throws: auditing must not break the audited action.
 *
 * @param  array<string, mixed>  $metadata
 */
function audit_log(string $action, ?Model $subject = null, array $metadata = [], Team|int|null $team = null, ?string $actorType = null): void
{
    try {
        $user = auth()->user();
        if (is_null($team) && $subject) {
            $team = platformTeamOf($subject);
        }
        if (is_null($team)) {
            $team = $user ? currentTeam() : null;
        }
        $request = app()->runningInConsole() ? null : request();
        // Actions taken while an operator accesses the dashboard as the customer.
        $impersonator = $user ? \App\Services\Platform\Impersonation::impersonatorId() : null;
        if ($impersonator) {
            $metadata = array_merge($metadata, ['impersonated_by' => $impersonator]);
        }
        $write = fn () => AuditLog::create([
            'team_id' => $team instanceof Team ? $team->id : $team,
            'user_id' => $user?->id,
            'actor_type' => $actorType ?? ($impersonator ? 'operator' : ($user ? (request()?->bearerToken() ? 'api' : 'user') : 'system')),
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? str((string) $request->userAgent())->limit(250, '')->value() : null,
            'metadata' => $metadata ?: null,
        ]);
        // Inside a transaction a failed insert aborts it (PostgreSQL): a savepoint keeps the audited action going.
        $connection = (new AuditLog)->getConnection();
        $connection->transactionLevel() > 0 ? $connection->transaction($write) : $write();
    } catch (\Throwable $e) {
        report($e);
    }
}
