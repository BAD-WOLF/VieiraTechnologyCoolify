<?php

namespace App\Services\Platform;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Platform\ProvisionTenant;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\PlatformNoticeJob;
use App\Models\Application;
use App\Models\PlatformTenant;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Visus\Cuid2\Cuid2;

/**
 * Tenant states driven by billing and quotas (wiki 8, "Billing alerts & enforcement"):
 *  - 80% of a quota            -> e-mail + in-app warning
 *  - over quota                -> "limited": new deployments/starts blocked (soft throttle)
 *  - invoice unpaid > 7 days   -> "suspended": new deployments blocked
 *  - invoice unpaid > 30 days  -> "hard_suspended": backups taken, containers stopped (data kept)
 *  - paid again                -> "active", stopped workloads are started again
 */
class TenantLifecycle
{
    public const REASON_OVERDUE = 'invoice_overdue';

    public const REASON_CANCELLED = 'subscription_cancelled';

    public const REASON_QUOTA = 'quota_exceeded';

    public const REASON_MANUAL = 'manual';

    public static function evaluate(PlatformTenant $tenant): PlatformTenant
    {
        if (! $tenant->hasPlan() || $tenant->status === PlatformTenant::STATUS_PENDING) {
            return $tenant;
        }
        $suspendAfter = (int) config('platform.enforcement.suspend_after_days', 7);
        $hardSuspendAfter = (int) config('platform.enforcement.hard_suspend_after_days', 30);

        if ($tenant->suspension_reason === self::REASON_MANUAL) {
            return $tenant;
        }

        if ($tenant->status === PlatformTenant::STATUS_CANCELLED) {
            if ($tenant->suspended_at && $tenant->suspended_at->lte(now()->subDays($suspendAfter)) && is_null($tenant->hard_suspended_at)) {
                self::hardSuspend($tenant, self::REASON_CANCELLED);
            }

            return $tenant->fresh();
        }

        if ($tenant->past_due_since) {
            $overdueDays = (int) $tenant->past_due_since->diffInDays(now());
            if ($overdueDays >= $hardSuspendAfter && is_null($tenant->hard_suspended_at)) {
                self::hardSuspend($tenant, self::REASON_OVERDUE);
            } elseif ($overdueDays >= $suspendAfter && ! in_array($tenant->status, [PlatformTenant::STATUS_SUSPENDED, PlatformTenant::STATUS_HARD_SUSPENDED], true)) {
                self::suspend($tenant, self::REASON_OVERDUE);
            }

            return $tenant->fresh();
        }

        if (in_array($tenant->status, [PlatformTenant::STATUS_SUSPENDED, PlatformTenant::STATUS_HARD_SUSPENDED], true)
            && $tenant->suspension_reason === self::REASON_OVERDUE) {
            self::reactivate($tenant);
            $tenant = $tenant->fresh();
        }

        return self::evaluateQuotas($tenant);
    }

    public static function evaluateQuotas(PlatformTenant $tenant): PlatformTenant
    {
        $snapshot = TenantUsage::snapshot($tenant);
        $overQuota = self::isOverQuota($snapshot);
        $peak = TenantUsage::peakUtilization($snapshot);

        if ($overQuota && $tenant->status === PlatformTenant::STATUS_ACTIVE) {
            $tenant->update([
                'status' => PlatformTenant::STATUS_LIMITED,
                'quota_exceeded_at' => now(),
                'suspension_reason' => self::REASON_QUOTA,
            ]);
            self::notify($tenant, __('Plan limit reached'), [
                __('Your team reached 100% of a plan quota. New deployments are paused until you free resources or upgrade the plan.'),
                self::describe($snapshot),
            ]);
        } elseif (! $overQuota && $tenant->status === PlatformTenant::STATUS_LIMITED) {
            $tenant->update(['status' => PlatformTenant::STATUS_ACTIVE, 'quota_exceeded_at' => null, 'suspension_reason' => null]);
        }

        $warningPercent = (int) config('platform.enforcement.quota_warning_percent', 80);
        [$periodStart] = TenantUsage::currentPeriod($tenant);
        $alreadyWarned = $tenant->quota_warning_notified_at && $tenant->quota_warning_notified_at->gte($periodStart);
        if ($peak >= $warningPercent && ! $overQuota && ! $alreadyWarned) {
            $tenant->update(['quota_warning_notified_at' => now()]);
            self::notify($tenant, __('You are close to your plan limits'), [
                __('Your team is using :percent% of a plan quota.', ['percent' => (int) $peak]),
                self::describe($snapshot),
            ]);
        }

        return $tenant->fresh();
    }

    /**
     * @param  array<string, array{used: float|int, limit: float|int, percent: float|null}>  $snapshot
     */
    public static function isOverQuota(array $snapshot): bool
    {
        if (($snapshot['storage_gb']['percent'] ?? 0) >= 100) {
            return true;
        }
        foreach (['cpus', 'memory_mb', 'containers', 'resources'] as $key) {
            if (($snapshot[$key]['percent'] ?? 0) > 100) {
                return true;
            }
        }

        return false;
    }

    public static function suspend(PlatformTenant $tenant, string $reason): void
    {
        $tenant->update([
            'status' => PlatformTenant::STATUS_SUSPENDED,
            'suspended_at' => $tenant->suspended_at ?? now(),
            'suspension_reason' => $reason,
        ]);
        self::notify($tenant, __('Deployments suspended'), [
            (string) $tenant->blockReason(),
            __('Your applications keep running. If the invoice stays unpaid the containers will be stopped.'),
        ]);
    }

    /**
     * Take backups, then stop every workload of the tenant (volumes and data are kept).
     */
    public static function hardSuspend(PlatformTenant $tenant, string $reason): void
    {
        $stopped = [];
        $backupsTriggered = false;
        foreach (self::workloads($tenant->team_id) as $resource) {
            if (! str((string) data_get($resource, 'status'))->startsWith(['running', 'starting', 'restarting', 'degraded'])
                && ! ($resource instanceof Service)) {
                continue;
            }
            $stopped[] = ['type' => $resource->getMorphClass(), 'id' => $resource->getKey()];
            if (in_array($resource::class, PlatformGate::databaseModels(), true)) {
                ScheduledDatabaseBackup::query()
                    ->where('database_type', $resource->getMorphClass())
                    ->where('database_id', $resource->getKey())
                    ->where('enabled', true)
                    ->get()
                    ->each(function ($backup) use (&$backupsTriggered) {
                        DatabaseBackupJob::dispatch($backup);
                        $backupsTriggered = true;
                    });
            }
        }
        $delay = $backupsTriggered ? now()->addMinutes(15) : now();
        foreach ($stopped as $entry) {
            $resource = $entry['type']::find($entry['id']);
            if (is_null($resource)) {
                continue;
            }
            match (true) {
                $resource instanceof Application => StopApplication::dispatch($resource)->delay($delay),
                $resource instanceof Service => StopService::dispatch($resource)->delay($delay),
                default => StopDatabase::dispatch($resource)->delay($delay),
            };
        }
        $tenant->update([
            'status' => PlatformTenant::STATUS_HARD_SUSPENDED,
            'hard_suspended_at' => now(),
            'suspended_at' => $tenant->suspended_at ?? now(),
            'suspension_reason' => $reason,
            'stopped_resources' => array_values(array_merge((array) $tenant->stopped_resources, $stopped)),
        ]);
        self::notify($tenant, __('Your workloads were stopped'), [
            (string) $tenant->fresh()->blockReason(),
            __('Your data and volumes are kept. Everything is started again automatically after the payment.'),
        ]);
    }

    /**
     * Back to active: clear the suspension and start what the suspension stopped.
     */
    public static function reactivate(PlatformTenant $tenant): void
    {
        $toStart = (array) $tenant->stopped_resources;
        $tenant->update([
            'status' => PlatformTenant::STATUS_ACTIVE,
            'past_due_since' => null,
            'suspended_at' => null,
            'hard_suspended_at' => null,
            'suspension_reason' => null,
            'stopped_resources' => null,
        ]);
        if (is_null($tenant->provisioned_at)) {
            ProvisionTenant::dispatch($tenant->team_id);
        }
        foreach ($toStart as $entry) {
            $class = data_get($entry, 'type');
            $resource = class_exists((string) $class) ? $class::find(data_get($entry, 'id')) : null;
            if (is_null($resource)) {
                continue;
            }
            try {
                match (true) {
                    $resource instanceof Application => queue_application_deployment(application: $resource, deployment_uuid: (string) new Cuid2),
                    $resource instanceof Service => StartService::dispatch($resource),
                    default => StartDatabase::dispatch($resource),
                };
            } catch (\Throwable $e) {
                report($e);
            }
        }
        self::notify($tenant, __('Your account is active again'), [
            __('Thank you! Deployments are enabled and stopped workloads are being started again.'),
        ]);
    }

    /**
     * @return array<int, Model>
     */
    public static function workloads(int $teamId): array
    {
        $resources = Application::query()->withoutGlobalScopes()
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))
            ->get()->all();
        $resources = array_merge($resources, Service::query()->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))->get()->all());
        foreach (PlatformGate::databaseModels() as $model) {
            $resources = array_merge($resources, $model::query()->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))->get()->all());
        }

        return $resources;
    }

    /**
     * @param  array<string, array{used: float|int, limit: float|int, percent: float|null}>  $snapshot
     */
    public static function describe(array $snapshot): string
    {
        $parts = [];
        foreach (['cpus' => 'vCPU', 'memory_mb' => 'MB RAM', 'containers' => 'containers', 'resources' => 'resources', 'storage_gb' => 'GB storage'] as $key => $unit) {
            $item = $snapshot[$key] ?? null;
            if ($item && $item['limit'] > 0) {
                $parts[] = "{$item['used']} / {$item['limit']} {$unit}";
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<int, string>  $lines
     */
    public static function notify(PlatformTenant $tenant, string $subject, array $lines): void
    {
        $team = $tenant->team;
        if (is_null($team)) {
            return;
        }
        PlatformNoticeJob::dispatch($team, $subject, array_values(array_filter($lines)), route('subscription.show'), __('Plan & usage'));
    }
}
