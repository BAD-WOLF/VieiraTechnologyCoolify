<?php

namespace App\Actions\Platform;

use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\Platform\TenantNetworks;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Visus\Cuid2\Cuid2;

/**
 * Tenant onboarding (wiki: "criar um novo tenant: team, network destination, quotas, domínio").
 *
 * Gives the team a region and an isolated Docker network ("tenant-{team}") on every node of that
 * region it may use: the shared nodes, or its dedicated nodes for plans with dedicated servers.
 * Safe to run repeatedly (also used to sync tenants when a node is added to a region).
 */
class ProvisionTenant
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Team|int $team, ?PlatformRegion $region = null, bool $createNetworks = true): PlatformTenant
    {
        $tenant = PlatformTenant::forTeam($team);
        $region ??= $tenant->region ?? PlatformRegion::defaultRegion();
        if (is_null($region)) {
            throw new \RuntimeException('No active platform region. Create a region and assign nodes to it first.');
        }
        if ((int) $tenant->platform_region_id !== (int) $region->id) {
            $tenant->update(['platform_region_id' => $region->id]);
        }

        $network = TenantNetworks::nameFor($tenant->team_id);
        foreach (self::nodesFor($tenant, $region) as $node) {
            $destination = StandaloneDocker::query()
                ->where('server_id', $node->id)
                ->where('team_id', $tenant->team_id)
                ->first();
            if (is_null($destination)) {
                $destination = new StandaloneDocker([
                    'uuid' => (string) new Cuid2,
                    'name' => "{$network}-{$region->slug}",
                    'network' => $network,
                    'server_id' => $node->id,
                    'team_id' => $tenant->team_id,
                ]);
                $destination->saveQuietly();
            }
            if ($createNetworks) {
                self::ensureNetwork($node, $network, $tenant->team_id);
            }
        }

        if (is_null($tenant->provisioned_at)) {
            $tenant->update(['provisioned_at' => now()]);
        }
        audit_log('tenant.provisioned', $tenant, ['region' => $region->slug], $tenant->team_id, 'system');

        return $tenant->fresh();
    }

    /**
     * Nodes of the region the tenant runs on: dedicated nodes when it has them, shared nodes otherwise.
     *
     * @return Collection<int, Server>
     */
    public static function nodesFor(PlatformTenant $tenant, PlatformRegion $region): Collection
    {
        $dedicated = Server::query()
            ->where('team_id', 0)
            ->where('platform_region_id', $region->id)
            ->where('platform_dedicated_team_id', $tenant->team_id)
            ->get();
        if ($dedicated->isNotEmpty()) {
            return $dedicated;
        }

        return $region->sharedNodes()->get();
    }

    /**
     * Create the tenant network on the node (bridge, isolated from other tenants) and attach the proxy.
     */
    public static function ensureNetwork(Server $node, string $network, int $teamId): void
    {
        if (! $node->isFunctional()) {
            return;
        }
        $name = escapeshellarg($network);
        instant_remote_process([
            "docker network inspect {$name} >/dev/null 2>&1 || docker network create --driver bridge --attachable --label coolify.managed=true --label coolify.tenantId={$teamId} {$name} >/dev/null",
            "docker network connect {$name} coolify-proxy >/dev/null 2>&1 || true",
        ], $node, false);
    }
}
