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
            throw PlatformPolicyViolation::single(__('Your plan allows :limit resources. Delete a resource or upgrade your plan.', ['limit' => $limit]), 'Plan limit reached');
        }
        $isDatabase = in_array($resource::class, self::databaseModels(), true);
        if ($isDatabase && ! $tenant->hasFeature('databases')) {
            throw PlatformPolicyViolation::single(PlanCatalog::missingFeature('databases'), 'Not included in your plan');
        }
        $isCompose = $resource instanceof Service || ($resource instanceof Application && $resource->build_pack === 'dockercompose');
        if ($isCompose && ! $tenant->hasFeature('docker_compose')) {
            throw PlatformPolicyViolation::single(PlanCatalog::missingFeature('docker_compose'), 'Not included in your plan');
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
                    throw PlatformPolicyViolation::single(__('Your plan allows :limit deployments per day.', ['limit' => $limit]), 'Plan limit reached');
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
            $perServer = self::reservedPerServer($team->id, function ($query) use ($resource, $serverId, $pullRequestId) {
                $query->where('resource_type', '!=', $resource->getMorphClass())
                    ->orWhere('resource_id', '!=', $resource->getKey())
                    ->orWhere('server_id', '!=', $serverId)
                    ->orWhere('pull_request_id', '!=', $pullRequestId);
            });
            $perServer[$serverId] ??= ['cpus' => 0.0, 'memory_mb' => 0, 'containers' => 0];
            $perServer[$serverId]['cpus'] += $result['cpus'];
            $perServer[$serverId]['memory_mb'] += $result['memory_mb'];
            $perServer[$serverId]['containers'] += $result['containers'];
            $quotas = $tenant->quotas();
            $total = self::effective($perServer, $quotas);
            $servers = count(array_filter($perServer, fn (array $reserved) => $reserved['containers'] > 0));
            $where = $servers > 1 ? sprintf(' on %d servers: run them on the same server, where they share the plan, or lower their limits', $servers) : '';
            $violations = [];
            if ($quotas['cpus'] > 0 && $total['cpus'] > (float) $quotas['cpus'] + 0.0001) {
                $violations[] = sprintf('CPU quota exceeded (%.2f of %.2f vCPU%s).', $total['cpus'], $quotas['cpus'], $where);
            }
            if ($quotas['memory_mb'] > 0 && $total['memory_mb'] > (int) $quotas['memory_mb']) {
                $violations[] = "Memory quota exceeded ({$total['memory_mb']} of {$quotas['memory_mb']} MB{$where}).";
            }
            if ($quotas['containers'] > 0 && $total['containers'] > (int) $quotas['containers']) {
                $violations[] = "Container quota exceeded ({$total['containers']} of {$quotas['containers']}).";
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

        self::logNotices($resource, $pullRequestId, [...$result['notices'], ...self::projectMountNotices($resource, $result['compose'])]);

        return $result['compose'];
    }

    /**
     * A compose file that mounts directories of the project (".:/app", "./src:/app/src") only finds
     * the files of the repository there when Coolify keeps them after the build.
     *
     * @param  array<string, mixed>  $compose
     * @return array<int, string>
     */
    private static function projectMountNotices(Model $resource, array $compose): array
    {
        if (! $resource instanceof Application || $resource->build_pack !== 'dockercompose' || (bool) $resource->settings?->is_preserve_repository_enabled) {
            return [];
        }
        $directory = base_configuration_dir()."/applications/{$resource->uuid}";
        $directories = $resource->fileStorages()->where('is_directory', true)->pluck('fs_path')->all();
        $notices = [];
        foreach ((array) data_get($compose, 'services', []) as $name => $service) {
            foreach ((array) data_get($service, 'volumes', []) as $volume) {
                $source = is_string($volume) ? (string) parseDockerVolumeString($volume)['source'] : (string) data_get($volume, 'source', '');
                if (($source === $directory || str_starts_with($source, $directory.'/')) && in_array($source, $directories, true)) {
                    $notices[] = "Service \"{$name}\" mounts a directory of the project: turn on \"Preserve Repository During Deployment\" so the files of your repository are there.";

                    continue 2;
                }
            }
        }

        return $notices;
    }

    /**
     * What the hardening changed goes into the log of the deployment running for the resource.
     *
     * @param  array<int, string>  $notices
     */
    private static function logNotices(Model $resource, int $pullRequestId, array $notices): void
    {
        if ($notices === [] || ! $resource instanceof Application) {
            return;
        }
        $deployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $resource->id)
            ->where('pull_request_id', $pullRequestId)
            ->where('status', \App\Enums\ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->latest('id')
            ->first();
        foreach ($notices as $notice) {
            $deployment?->addLogEntry($notice);
        }
    }

    /**
     * Reservations of a team per server.
     *
     * @param  (callable(\Illuminate\Database\Eloquent\Builder<PlatformAllocation>): mixed)|null  $filter
     * @return array<int, array{cpus: float, memory_mb: int, containers: int}>
     */
    public static function reservedPerServer(int $teamId, ?callable $filter = null): array
    {
        $rows = PlatformAllocation::query()
            ->where('team_id', $teamId)
            ->when($filter, fn ($query) => $query->where($filter))
            ->groupBy('server_id')
            ->selectRaw('server_id, COALESCE(SUM(cpus), 0) as cpus, COALESCE(SUM(memory_mb), 0) as memory_mb, COALESCE(SUM(containers), 0) as containers')
            ->get();
        $perServer = [];
        foreach ($rows as $row) {
            $perServer[(int) $row->server_id] = ['cpus' => (float) $row->cpus, 'memory_mb' => (int) $row->memory_mb, 'containers' => (int) $row->containers];
        }

        return $perServer;
    }

    /**
     * What reservations count against the plan. The containers of a tenant on a node share one
     * slice capped at the plan (hostrema-tenant-limits), so they may each use up to the whole plan
     * and a node never counts for more than the plan; only resources spread over several nodes add
     * up beyond it. With "shared_plan" off every container limit counts in full.
     *
     * @param  array<int, array{cpus: float, memory_mb: int, containers: int}>  $perServer
     * @param  array<string, float|int>  $quotas
     * @return array{cpus: float, memory_mb: int, containers: int}
     */
    public static function effective(array $perServer, array $quotas): array
    {
        $capped = TenantLimits::sharesPlan();
        $total = ['cpus' => 0.0, 'memory_mb' => 0, 'containers' => 0];
        foreach ($perServer as $reserved) {
            $total['cpus'] += $capped && (float) $quotas['cpus'] > 0 ? min($reserved['cpus'], (float) $quotas['cpus']) : $reserved['cpus'];
            $total['memory_mb'] += $capped && (int) $quotas['memory_mb'] > 0 ? min($reserved['memory_mb'], (int) $quotas['memory_mb']) : $reserved['memory_mb'];
            $total['containers'] += $reserved['containers'];
        }
        $total['cpus'] = round($total['cpus'], 3);

        return $total;
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
     * Current reservations of a team, as counted against its plan (see effective()).
     *
     * @param  array<string, float|int>|null  $quotas
     * @return array{cpus: float, memory_mb: int, containers: int}
     */
    public static function allocated(Team|int $team, ?array $quotas = null): array
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        return self::effective(self::reservedPerServer($teamId), $quotas ?? PlatformTenant::forTeam($teamId)->quotas());
    }
}
