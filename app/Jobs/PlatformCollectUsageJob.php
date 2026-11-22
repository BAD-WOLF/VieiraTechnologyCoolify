<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fan-out of the metering collection to every platform node (scheduled every few minutes).
 */
class PlatformCollectUsageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 60;

    /**
     * The unique lock expires on its own: a job lost from the queue (horizon:clear, a Redis
     * restart) must not stop this job for good.
     */
    public int $uniqueFor = 600;

    public function handle(): void
    {
        if (! isPlatform()) {
            return;
        }
        $interval = max(1, (int) config('platform.metering.interval_minutes', 5));
        $withStorage = now()->minute < $interval;
        Server::query()
            ->where('team_id', 0)
            ->whereNotNull('platform_region_id')
            ->whereRelation('settings', 'is_reachable', true)
            ->whereRelation('settings', 'is_usable', true)
            ->pluck('id')
            ->each(fn ($serverId) => PlatformCollectServerUsageJob::dispatch($serverId, $withStorage));
    }
}
