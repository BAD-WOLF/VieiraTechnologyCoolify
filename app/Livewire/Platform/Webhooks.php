<?php

namespace App\Livewire\Platform;

use App\Models\PlatformWebhook;
use App\Models\PlatformWebhookDelivery;
use App\Services\Platform\Webhooks as WebhookService;
use Livewire\Component;

/**
 * Webhooks of the current team: signed HTTP POSTs for deployments, resources, backups, plan and
 * invoices. Managed by team admins.
 */
class Webhooks extends Component
{
    public string $url = '';

    /** @var list<string> */
    public array $groups = ['all'];

    public string $description = '';

    public bool $allTeams = false;

    public ?string $newSecret = null;

    public ?string $newSecretFor = null;

    public function create(): void
    {
        if (! $this->canManage()) {
            return;
        }
        $this->validate([
            'url' => ['required', 'string', 'max:2048'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['in:'.implode(',', array_keys(WebhookService::GROUPS))],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $events = in_array('all', $this->groups, true)
            ? ['*']
            : collect($this->groups)->flatMap(fn ($group) => WebhookService::GROUPS[$group])->values()->all();
        try {
            $webhook = WebhookService::create((int) currentTeam()->id, $this->url, $events, $this->description, $this->allTeams);
        } catch (\InvalidArgumentException $e) {
            $this->addError('url', $e->getMessage());

            return;
        }
        $this->newSecret = $webhook->secret;
        $this->newSecretFor = $webhook->uuid;
        $this->reset(['url', 'description', 'allTeams']);
        $this->groups = ['all'];
        $this->dispatch('success', __('Webhook created.'));
    }

    public function toggle(string $uuid): void
    {
        $webhook = $this->find($uuid);
        if ($webhook) {
            $webhook->update(['enabled' => ! $webhook->enabled, 'consecutive_failures' => 0, 'disabled_reason' => null]);
        }
    }

    public function test(string $uuid): void
    {
        $webhook = $this->find($uuid);
        if ($webhook) {
            WebhookService::sendTest($webhook);
            $this->dispatch('success', __('Test event queued.'));
        }
    }

    public function rotate(string $uuid): void
    {
        $webhook = $this->find($uuid);
        if ($webhook) {
            $webhook->update(['secret' => PlatformWebhook::newSecret()]);
            audit_log('webhook.secret_rotated', $webhook, [], (int) $webhook->team_id);
            $this->newSecret = $webhook->secret;
            $this->newSecretFor = $webhook->uuid;
        }
    }

    public function delete(string $uuid): void
    {
        $webhook = $this->find($uuid);
        if ($webhook) {
            $webhook->delete();
            audit_log('webhook.deleted', $webhook, ['url' => WebhookService::displayUrl($webhook->url)], (int) $webhook->team_id);
            $this->dispatch('success', __('Webhook deleted.'));
        }
    }

    public function resend(string $deliveryUuid): void
    {
        if (! $this->canManage()) {
            return;
        }
        $delivery = PlatformWebhookDelivery::query()->where('uuid', $deliveryUuid)
            ->whereHas('webhook', fn ($query) => $query->where('team_id', currentTeam()->id))->first();
        if ($delivery) {
            WebhookService::resend($delivery);
            $this->dispatch('success', __('Delivery queued again.'));
        }
    }

    public function render()
    {
        $teamId = (int) currentTeam()?->id;

        return view('livewire.platform.webhooks', [
            'webhooks' => PlatformWebhook::query()->where('team_id', $teamId)->orderBy('id')
                ->with(['deliveries' => fn ($query) => $query->latest('id')->limit(10)])->get(),
            'canManage' => $this->isManager(),
            'isOperator' => $teamId === 0,
            'groupOptions' => [
                'all' => __('All events'),
                'deployments' => __('Deployments'),
                'resources' => __('Resources (created, deleted, started, stopped)'),
                'backups' => __('Database backups'),
                'billing' => __('Plan and invoices'),
            ],
        ]);
    }

    private function find(string $uuid): ?PlatformWebhook
    {
        if (! $this->canManage()) {
            return null;
        }

        return PlatformWebhook::query()->where('team_id', currentTeam()->id)->where('uuid', $uuid)->first();
    }

    private function isManager(): bool
    {
        return isPlatform() && (bool) auth()->user()?->isAdmin();
    }

    private function canManage(): bool
    {
        if (! $this->isManager()) {
            $this->dispatch('error', __('Only team admins can manage webhooks.'));

            return false;
        }

        return true;
    }
}
