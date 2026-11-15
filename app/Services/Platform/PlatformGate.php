<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformPolicyViolation;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PlatformAllocation;
use App\Models\PlatformTenant;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Runtime enforcement of plans and isolation for tenant workloads.
 */
class PlatformGate
{
    /**
     * Whether the model belongs to a platform tenant (and therefore must be enforced).
     */
    public static function applies(?Model $resource): bool
    {
        if (! isPlatform() || is_null($resource)) {
            return false;
        }
        $team = platformTeamOf($resource);

        return ! is_null($team) && isPlatformTenant($team);
    }

    /**
     * Resources (applications, databases, services) owned by the team.
     */
    public static function resourceCount(Team|int $team): int
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        $count = Application::query()->withoutGlobalScopes()->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))->count();
        $count += Service::query()->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))->count();
        foreach (self::databaseModels() as $model) {
            $count += $model::query()->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))->count();
        }

        return $count;
    }

    /**
     * @return array<int, class-string<Model>>
     */
    public static function databaseModels(): array
    {
        return [
            \App\Models\StandalonePostgresql::class,
            \App\Models\StandaloneMysql::class,
            \App\Models\StandaloneMariadb::class,
            \App\Models\StandaloneMongodb::class,
            \App\Models\StandaloneRedis::class,
            \App\Models\StandaloneKeydb::class,
            \App\Models\StandaloneDragonfly::class,
            \App\Models\StandaloneClickhouse::class,
        ];
    }

    public static function assertTenantActive(Team|int $team): PlatformTenant
    {
        $tenant = PlatformTenant::forTeam($team);
        if ($tenant->isBlocked()) {
            throw PlatformPolicyViolation::single((string) $tenant->blockReason(), 'Not allowed');
        }

        return $tenant;
    }

    public static function assertCanCreateResource(Model $resource): void
    {
        if (! self::applies($resource)) {
            return;
        }
        $team = platformTeamOf($resource);
        $tenant = self::assertTenantActive($team);
        $limit = (int) $tenant->quota('resources');
        if ($limit > 0 && self::resourceCount($team) >= $limit) {
            throw PlatformPolicyViolation::single("Your plan allows {$limit} resources. Delete a resource or upgrade your plan.", 'Plan limit reached');
        }
        $isDatabase = in_array($resource::class, self::databaseModels(), true);
        if ($isDatabase && ! $tenant->hasFeature('databases')) {
            throw PlatformPolicyViolation::single('Databases are not included in your plan.', 'Plan limit reached');
        }
        $isCompose = $resource instanceof Service || ($resource instanceof Application && $resource->build_pack === 'dockercompose');
        if ($isCompose && ! $tenant->hasFeature('docker_compose')) {
            throw PlatformPolicyViolation::single('Docker Compose stacks are not included in your plan.', 'Plan limit reached');
        }
    }

    /**
     * Called before any deployment or start of a tenant workload.
     */
    public static function assertCanDeploy(Model $resource): void
    {
        if (! self::applies($resource)) {
            return;
        }
        $team = platformTeamOf($resource);
        $tenant = self::assertTenantActive($team);
        if ($resource instanceof Application) {
            $limit = (int) $tenant->quota('deployments_per_day');
            if ($limit > 0) {
                $applicationIds = Application::query()->withoutGlobalScopes()
                    ->whereHas('environment.project', fn ($query) => $query->where('team_id', $team->id))
                    ->pluck('id');
                $deployments = ApplicationDeploymentQueue::query()
                    ->whereIn('application_id', $applicationIds)
                    ->where('created_at', '>=', now()->subDay())
                    ->count();
                if ($deployments > $limit) {
                    throw PlatformPolicyViolation::single("Your plan allows {$limit} deployments per day.", 'Plan limit reached');
                }
            }
        }
    }

    /**
     * Make sure the tenant network exists on the node before a workload is started on it
     * (networks without containers can be removed by docker cleanups).
     */
    public static function ensureNetwork(Model $resource, ?\App\Models\Server $server = null, ?\App\Models\StandaloneDocker $destination = null): void
    {
        if (! self::applies($resource)) {
            return;
        }
        $destination ??= data_get($resource, 'destination');
        if (! $destination instanceof \App\Models\StandaloneDocker || is_null($destination->team_id)) {
            return;
        }
        $server ??= $destination->server;
        \App\Actions\Platform\ProvisionTenant::ensureNetwork($server, $destination->network, (int) $destination->team_id);
    }

    /**
     * Validate + harden the compose definition of a tenant workload and reserve its resources.
     *
     * @param  array<string, mixed>  $compose
     * @return array<string, mixed>
     */
    public static function harden(Model $resource, array $compose, ?int $serverId = null, int $pullRequestId = 0, bool $reserve = true): array
    {
        if (! self::applies($resource)) {
            return $compose;
        }
        $team = platformTeamOf($resource);
        $tenant = PlatformTenant::forTeam($team);
        $serverId ??= (int) data_get($resource, 'destination.server_id', 0);
        $context = HardeningContext::for($resource, $team, $pullRequestId, $serverId ?: null);
        $result = (new TenantContainerPolicy($context))->apply($compose);
        if (! $reserve) {
            return $result['compose'];
        }

        DB::transaction(function () use ($tenant, $team, $resource, $serverId, $pullRequestId, $result) {
            PlatformTenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $others = PlatformAllocation::query()
                ->where('team_id', $team->id)
                ->where(function ($query) use ($resource, $serverId, $pullRequestId) {
                    $query->where('resource_type', '!=', $resource->getMorphClass())
                        ->orWhere('resource_id', '!=', $resource->getKey())
                        ->orWhere('server_id', '!=', $serverId)
                        ->orWhere('pull_request_id', '!=', $pullRequestId);
                })
                ->selectRaw('COALESCE(SUM(cpus), 0) as cpus, COALESCE(SUM(memory_mb), 0) as memory_mb, COALESCE(SUM(containers), 0) as containers')
                ->first();
            $quotas = $tenant->quotas();
            $violations = [];
            $cpus = (float) $others->cpus + $result['cpus'];
            $memory = (int) $others->memory_mb + $result['memory_mb'];
            $containers = (int) $others->containers + $result['containers'];
            if ($quotas['cpus'] > 0 && $cpus > (float) $quotas['cpus'] + 0.0001) {
                $violations[] = sprintf('CPU quota exceeded (%.2f of %.2f vCPU).', $cpus, $quotas['cpus']);
            }
            if ($quotas['memory_mb'] > 0 && $memory > (int) $quotas['memory_mb']) {
                $violations[] = "Memory quota exceeded ({$memory} of {$quotas['memory_mb']} MB).";
            }
            if ($quotas['containers'] > 0 && $containers > (int) $quotas['containers']) {
                $violations[] = "Container quota exceeded ({$containers} of {$quotas['containers']}).";
            }
            if (count($violations) > 0) {
                throw new PlatformPolicyViolation($violations, 'Plan quota exceeded (stop another resource, lower its limits or upgrade your plan)');
            }
            PlatformAllocation::query()->updateOrCreate(
                [
                    'resource_type' => $resource->getMorphClass(),
                    'resource_id' => $resource->getKey(),
                    'server_id' => $serverId,
                    'pull_request_id' => $pullRequestId,
                ],
                [
                    'team_id' => $team->id,
                    'cpus' => $result['cpus'],
                    'memory_mb' => $result['memory_mb'],
                    'containers' => $result['containers'],
                ],
            );
        });

        return $result['compose'];
    }

    /**
     * Release the resources reserved by a stopped or deleted workload.
     */
    public static function release(Model $resource, ?int $serverId = null, ?int $pullRequestId = null): void
    {
        if (! isPlatform()) {
            return;
        }
        $query = PlatformAllocation::query()
            ->where('resource_type', $resource->getMorphClass())
            ->where('resource_id', $resource->getKey());
        if (! is_null($serverId)) {
            $query->where('server_id', $serverId);
        }
        if (! is_null($pullRequestId)) {
            $query->where('pull_request_id', $pullRequestId);
        }
        $query->delete();
    }

    /**
     * Current reservations of a team.
     *
     * @return array{cpus: float, memory_mb: int, containers: int}
     */
    public static function allocated(Team|int $team): array
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        $totals = PlatformAllocation::query()
            ->where('team_id', $teamId)
            ->selectRaw('COALESCE(SUM(cpus), 0) as cpus, COALESCE(SUM(memory_mb), 0) as memory_mb, COALESCE(SUM(containers), 0) as containers')
            ->first();

        return [
            'cpus' => round((float) $totals->cpus, 3),
            'memory_mb' => (int) $totals->memory_mb,
            'containers' => (int) $totals->containers,
        ];
    }
}
