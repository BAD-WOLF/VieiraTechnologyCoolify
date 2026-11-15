<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised for every platform event (deployments, resources, containers, tenants, invoices,
 * backups). Extensions of the fork listen to it like any Laravel event:
 *
 *     Event::listen(PlatformEventOccurred::class, function (PlatformEventOccurred $event) {
 *         if ($event->type === 'deployment.failed') { ... }
 *     });
 *
 * The same payload goes to the Redis stream and to the webhooks (docs/extensions.md).
 */
class PlatformEventOccurred
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?int $teamId,
        public array $data,
        public string $occurredAt,
    ) {}

    /**
     * @return array{id: string, type: string, created_at: string, team_id: ?int, data: array<string, mixed>}
     */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'created_at' => $this->occurredAt,
            'team_id' => $this->teamId,
            'data' => $this->data,
        ];
    }
}
