<?php

namespace App\Services\Platform;

use App\Models\PlatformTenant;
use App\Models\Server;

/**
 * Limits of a tenant as a whole on each node (wiki 4, "cgroups slices por tenant", I/O throttling
 * and tc): all its containers share the slice tenant-<id>.slice, capped with the plan quotas by
 * the node-side script hostrema-tenant-limits; the plan bandwidth is applied to the tenant bridge.
 */
class TenantLimits
{
    public static function enabled(): bool
    {
        return (bool) config('platform.container.tenant_slices', true);
    }

    /**
     * Whether the containers of a tenant on a node share its plan: each one may use up to the
     * whole plan and the tenant slice caps the total, so the limits declared by the containers
     * do not add up against the plan (see PlatformGate::effective). Off, every container limit
     * counts in full. Production keeps it with the slices; the local environment, whose node can
     * not run slices, keeps it to behave like production.
     */
    public static function sharesPlan(): bool
    {
        return (bool) config('platform.container.shared_plan', true);
    }

    public static function sliceName(int $teamId): string
    {
        return "tenant-{$teamId}.slice";
    }

    /**
     * @return array{cpus: float, memory_mb: int, tasks: int, read_mbps: int, write_mbps: int, iops: int, bandwidth_mbps: int}
     */
    public static function of(PlatformTenant $tenant): array
    {
        $quotas = $tenant->quotas();

        return [
            'cpus' => max(0.0, (float) ($quotas['cpus'] ?? 0)),
            'memory_mb' => max(0, (int) ($quotas['memory_mb'] ?? 0)),
            'tasks' => max(64, (int) ($quotas['pids_per_container'] ?? 256) * max(1, (int) ($quotas['containers'] ?? 1))),
            'read_mbps' => max(0, (int) ($quotas['disk_read_mbps'] ?? 0)),
            'write_mbps' => max(0, (int) ($quotas['disk_write_mbps'] ?? 0)),
            'iops' => max(0, (int) ($quotas['disk_iops'] ?? 0)),
            'bandwidth_mbps' => max(0, (int) ($quotas['bandwidth_mbps'] ?? 0)),
        ];
    }

    /**
     * Shell command for the node (no-op on nodes without the script).
     */
    public static function command(PlatformTenant $tenant): string
    {
        $limits = self::of($tenant);
        $arguments = [
            'apply',
            (string) $tenant->team_id,
            '--network='.TenantNetworks::nameFor($tenant->team_id),
            '--cpus='.rtrim(rtrim(number_format($limits['cpus'], 3, '.', ''), '0'), '.'),
            '--memory-mb='.$limits['memory_mb'],
            '--tasks='.$limits['tasks'],
            '--read-mbps='.$limits['read_mbps'],
            '--write-mbps='.$limits['write_mbps'],
            '--iops='.$limits['iops'],
            '--bandwidth-mbps='.$limits['bandwidth_mbps'],
        ];

        return 'if command -v hostrema-tenant-limits >/dev/null 2>&1; then hostrema-tenant-limits '
            .implode(' ', array_map('escapeshellarg', $arguments)).' >/dev/null; fi';
    }

    public static function applyOn(Server $node, PlatformTenant $tenant): void
    {
        if (! self::enabled() || ! $node->isFunctional()) {
            return;
        }
        instant_remote_process([self::command($tenant)], $node, false);
    }
}
