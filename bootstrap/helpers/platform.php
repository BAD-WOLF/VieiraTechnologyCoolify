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
 */
function platformTeamOf(mixed $model): ?Team
{
    if (is_null($model)) {
        return null;
    }
    if ($model instanceof Team) {
        return $model;
    }
    if ($model instanceof ServiceApplication || $model instanceof ServiceDatabase) {
        return platformTeamOf($model->service ?? Service::find($model->service_id));
    }
    if ($model instanceof ApplicationSetting || $model instanceof ApplicationPreview) {
        return platformTeamOf($model->application ?? Application::find($model->application_id));
    }
    if ($model instanceof LocalFileVolume || $model instanceof LocalPersistentVolume) {
        if (blank($model->resource_type) || blank($model->resource_id) || ! class_exists($model->resource_type)) {
            return null;
        }

        return platformTeamOf($model->resource_type::find($model->resource_id));
    }
    if ($model instanceof EnvironmentVariable) {
        if (blank($model->resourceable_type) || blank($model->resourceable_id) || ! class_exists($model->resourceable_type)) {
            return null;
        }

        return platformTeamOf($model->resourceable_type::find($model->resourceable_id));
    }
    if ($model instanceof ScheduledTask) {
        return platformTeamOf($model->application_id ? Application::find($model->application_id) : Service::find($model->service_id));
    }
    if ($model instanceof ScheduledDatabaseBackup || $model instanceof S3Storage) {
        return $model->team_id !== null ? Team::find($model->team_id) : null;
    }
    if ($model instanceof Model && isset($model->environment_id)) {
        $environment = Environment::with('project')->find($model->environment_id);

        return $environment?->project?->team_id !== null ? Team::find($environment->project->team_id) : null;
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
        AuditLog::create([
            'team_id' => $team instanceof Team ? $team->id : $team,
            'user_id' => $user?->id,
            'actor_type' => $actorType ?? ($user ? (request()?->bearerToken() ? 'api' : 'user') : 'system'),
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? str((string) $request->userAgent())->limit(250, '')->value() : null,
            'metadata' => $metadata ?: null,
        ]);
    } catch (\Throwable $e) {
        report($e);
    }
}
