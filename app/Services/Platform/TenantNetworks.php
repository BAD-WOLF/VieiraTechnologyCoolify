<?php

namespace App\Services\Platform;

use App\Models\StandaloneDocker;
use App\Models\Team;

class TenantNetworks
{
    public static function nameFor(Team|int $team): string
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        return config('platform.tenant_network_prefix', 'tenant').'-'.$teamId;
    }

    /**
     * Docker networks the team may attach workloads to: its tenant network on the shared nodes
     * plus the networks of destinations on servers the team owns.
     *
     * @return array<int, string>
     */
    public static function namesFor(Team|int|null $team): array
    {
        if (is_null($team)) {
            return [];
        }
        $teamId = $team instanceof Team ? $team->id : $team;
        $owned = StandaloneDocker::query()
            ->where(function ($query) use ($teamId) {
                $query->where('team_id', $teamId)
                    ->orWhereHas('server', fn ($server) => $server->where('team_id', $teamId));
            })
            ->pluck('network')
            ->all();

        return array_values(array_unique(array_merge([self::nameFor($teamId)], $owned)));
    }
}
