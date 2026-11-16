<?php

namespace App\Services\Platform;

use App\Models\Application;
use App\Models\PlatformTenant;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything the container policy needs to know about the tenant workload being deployed.
 */
class HardeningContext
{
    /**
     * @param  array<int, string>  $bindRoots  host directories the workload may bind mount
     * @param  array<int, string>  $allowedNetworks  external docker networks the workload may join
     * @param  array<int, string>  $allowedHosts  domains owned by the tenant
     * @param  array<string, int|string>  $resourceIds  expected coolify.*Id label values
     */
    public function __construct(
        public int $teamId,
        public string $plan,
        public string $resourceUuid,
        public string $resourceKind,
        public string $baseDirectory,
        public array $bindRoots,
        public array $allowedNetworks,
        public array $allowedHosts,
        public float $maxCpus,
        public int $maxMemoryMb,
        public float $defaultCpus,
        public int $defaultMemoryMb,
        public int $pidsLimit,
        public array $resourceIds = [],
        public bool $dedicatedNode = false,
    ) {}

    public static function for(Model $resource, ?Team $team = null, int $pullRequestId = 0, ?int $serverId = null): self
    {
        $team ??= platformTeamOf($resource);
        $tenant = PlatformTenant::forTeam($team);
        $quotas = $tenant->quotas();
        $uuid = (string) data_get($resource, 'uuid');
        $kind = match (true) {
            $resource instanceof Application => 'application',
            $resource instanceof Service => 'service',
            default => 'database',
        };
        $roots = PathPolicy::rootsFor($resource);
        $networks = TenantNetworks::namesFor($team);
        $networks[] = $uuid;
        if ($pullRequestId !== 0) {
            $networks[] = "{$uuid}-{$pullRequestId}";
        }
        $network = data_get($resource, 'destination.network');
        if (filled($network) && in_array($network, TenantNetworks::namesFor($team), true)) {
            $networks[] = $network;
            if ($pullRequestId !== 0) {
                $networks[] = "{$network}-{$pullRequestId}";
            }
        }
        $resourceIds = match ($kind) {
            'application' => ['coolify.applicationid' => $resource->id],
            'service' => ['coolify.serviceid' => $resource->id],
            default => ['coolify.databaseid' => $resource->id],
        };
        $maxCpus = max(0.0, (float) $quotas['cpus']);
        $maxMemory = max(0, (int) $quotas['memory_mb']);

        return new self(
            teamId: $team->id,
            plan: (string) $tenant->plan,
            resourceUuid: $uuid,
            resourceKind: $kind,
            baseDirectory: $roots[0] ?? '/nonexistent',
            bindRoots: $roots,
            allowedNetworks: array_values(array_unique($networks)),
            allowedHosts: DomainPolicy::hostsOwnedBy($team),
            maxCpus: $maxCpus,
            maxMemoryMb: $maxMemory,
            defaultCpus: min((float) config('platform.container.default_cpus', 0.5), $maxCpus),
            defaultMemoryMb: min((int) config('platform.container.default_memory_mb', 512), $maxMemory),
            pidsLimit: max(32, (int) ($quotas['pids_per_container'] ?? 256)),
            resourceIds: $resourceIds,
            dedicatedNode: self::isDedicatedNode($resource, $serverId),
        );
    }

    /**
     * Context for a compose file that is not parsed yet (a one-click template or what a tenant
     * typed): its relative binds resolve inside a service directory, like after parsing.
     */
    public static function forUnparsedCompose(?string $resourceUuid = null): self
    {
        $resourceUuid = filled($resourceUuid) ? (string) $resourceUuid : 'unparsed-compose';
        $directory = service_configuration_dir()."/{$resourceUuid}";

        return new self(
            teamId: 0,
            plan: '',
            resourceUuid: $resourceUuid,
            resourceKind: 'service',
            baseDirectory: $directory,
            bindRoots: [$directory],
            allowedNetworks: [],
            allowedHosts: [],
            maxCpus: 1,
            maxMemoryMb: 1024,
            defaultCpus: 0.5,
            defaultMemoryMb: 512,
            pidsLimit: 256,
        );
    }

    /**
     * Enterprise VM reserved to the tenant (the VM is the isolation boundary there).
     */
    private static function isDedicatedNode(Model $resource, ?int $serverId): bool
    {
        $serverId ??= (int) (data_get($resource, 'destination.server_id') ?? data_get($resource, 'server_id') ?? 0);
        if ($serverId <= 0) {
            return false;
        }

        return \App\Models\Server::query()->whereKey($serverId)->whereNotNull('platform_dedicated_team_id')->exists();
    }
}
