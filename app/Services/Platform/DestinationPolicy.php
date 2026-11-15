<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformPolicyViolation;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Which servers/destinations a team may deploy to.
 *
 * Upstream resolves destinations by UUID without checking ownership, so a crafted URL or API call
 * could place a resource on another team's server or network. In cloud/platform mode a team may
 * only use destinations on its own servers or the tenant destinations created for it on the
 * shared nodes.
 */
class DestinationPolicy
{
    public static function isUsableBy(StandaloneDocker|SwarmDocker|null $destination, Team|int|null $team): bool
    {
        if (is_null($destination) || is_null($team)) {
            return false;
        }
        $teamId = $team instanceof Team ? $team->id : $team;
        if ((int) $teamId === 0) {
            return true;
        }
        $serverTeamId = (int) data_get($destination, 'server.team_id', -1);
        if ($destination instanceof StandaloneDocker && ! is_null($destination->team_id)) {
            return (int) $destination->team_id === (int) $teamId;
        }

        return $serverTeamId === (int) $teamId;
    }

    public static function assertResourceDestination(Model $resource): void
    {
        if (! isCloud()) {
            return;
        }
        $destinationType = $resource->getAttribute('destination_type');
        $destinationId = $resource->getAttribute('destination_id');
        if (blank($destinationType) || blank($destinationId) || ! class_exists($destinationType)) {
            return;
        }
        $team = platformTeamOf($resource);
        if (is_null($team) || (int) $team->id === 0) {
            return;
        }
        $destination = $destinationType::with('server')->find($destinationId);
        if (! self::isUsableBy($destination, $team)) {
            throw PlatformPolicyViolation::single('The selected server/destination does not belong to your team.', 'Destination not allowed');
        }
    }

    /**
     * Servers on which the team can run workloads: its own servers plus shared nodes hosting its tenant destinations.
     */
    public static function serversFor(Team|int $team)
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        return Server::query()
            ->where(function ($query) use ($teamId) {
                $query->where('team_id', $teamId)
                    ->orWhereIn('id', StandaloneDocker::query()->where('team_id', $teamId)->select('server_id'));
            });
    }

    /**
     * Destinations of a server the team may use.
     *
     * @return Collection<int, StandaloneDocker|SwarmDocker>
     */
    public static function destinationsOn(Server $server, Team|int $team): Collection
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        if ((int) $server->team_id === (int) $teamId || (int) $teamId === 0) {
            return $server->destinations();
        }

        return $server->standaloneDockers()->where('team_id', $teamId)->get();
    }

    /**
     * All destinations the team may deploy to.
     *
     * @return Collection<int, StandaloneDocker|SwarmDocker>
     */
    public static function destinationsFor(Team|int $team): Collection
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        $standalone = StandaloneDocker::query()
            ->with('server.settings')
            ->where(function ($query) use ($teamId) {
                $query->where('team_id', $teamId)
                    ->orWhere(function ($query) use ($teamId) {
                        $query->whereNull('team_id')->whereHas('server', fn ($server) => $server->where('team_id', $teamId));
                    });
            })
            ->get();
        $swarm = SwarmDocker::query()->with('server.settings')->whereHas('server', fn ($server) => $server->where('team_id', $teamId))->get();

        return $standalone->concat($swarm);
    }
}
