<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells the open dashboards to refresh the bell. Only the audience goes out: each dashboard reads
 * what it may see. Notifications of one team go on its private channel, the others on a public
 * one (they are announcements, and nothing of them is in the message).
 */
class PlatformNotificationPublished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public ?int $teamId = null) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [is_null($this->teamId) ? new Channel('platform-notifications') : new PrivateChannel("team.{$this->teamId}")];
    }
}
