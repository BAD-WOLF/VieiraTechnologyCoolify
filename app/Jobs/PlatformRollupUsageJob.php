<?php

namespace App\Jobs;

use App\Services\Platform\UsageAggregator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Hourly rollup of usage events (re-aggregates the last hours to include late samples).
 */
class PlatformRollupUsageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 600;

    /**
     * The unique lock expires on its own: a job lost from the queue (horizon:clear, a Redis
     * restart) must not stop this job for good.
     */
    public int $uniqueFor = 3600;

    public function __construct(public int $hours = 3) {}

    public function handle(): void
    {
        if (! isPlatform()) {
            return;
        }
        for ($i = $this->hours; $i >= 1; $i--) {
            UsageAggregator::aggregateHour(now()->subHours($i));
        }
        UsageAggregator::aggregateHour(now());
    }
}
