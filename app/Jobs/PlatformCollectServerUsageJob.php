<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Platform\UsageCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Collect container usage of one platform node and store usage events.
 */
class PlatformCollectServerUsageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public $timeout = 240;

    public function __construct(public int $serverId, public bool $withStorage = false) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(): void
    {
        $server = Server::find($this->serverId);
        if (is_null($server) || ! $server->isFunctional()) {
            return;
        }
        UsageCollector::collect($server, $this->withStorage);
    }
}
