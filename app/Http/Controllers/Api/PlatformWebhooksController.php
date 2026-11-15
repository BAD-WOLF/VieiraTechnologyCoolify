<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformWebhook;
use App\Services\Platform\PlatformEvents;
use App\Services\Platform\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhooks of the token team (wiki 9, "Limitações de API Interna": integrations without reading
 * the database). The secret is only returned when the webhook is created.
 */
class PlatformWebhooksController extends Controller
{
    public function eventTypes(): JsonResponse
    {
        return response()->json(collect(PlatformEvents::TYPES)->map(fn ($description, $type) => ['type' => $type, 'description' => $description])->values());
    }

    public function index(): JsonResponse
    {
        $teamId = $this->teamId();
        if ($teamId instanceof JsonResponse) {
            return $teamId;
        }

        return response()->json(PlatformWebhook::query()->where('team_id', $teamId)->orderBy('id')->get()->map(fn (PlatformWebhook $webhook) => $this->present($webhook)));
    }

    public function store(Request $request): JsonResponse
    {
        $teamId = $this->teamId();
        if ($teamId instanceof JsonResponse) {
            return $teamId;
        }
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'events' => ['required', 'array', 'min:1', 'max:30'],
            'events.*' => ['string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'all_teams' => ['nullable', 'boolean'],
        ]);
        try {
            $webhook = Webhooks::create($teamId, $validated['url'], $validated['events'], $validated['description'] ?? null, (bool) ($validated['all_teams'] ?? false));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($webhook) + ['secret' => $webhook->secret], 201);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $webhook = $this->find($uuid);
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }
        $webhook->delete();
        audit_log('webhook.deleted', $webhook, ['url' => Webhooks::displayUrl($webhook->url)], (int) $webhook->team_id);

        return response()->json(['message' => 'Webhook deleted.']);
    }

    public function test(string $uuid): JsonResponse
    {
        $webhook = $this->find($uuid);
        if ($webhook instanceof JsonResponse) {
            return $webhook;
        }
        $delivery = Webhooks::sendTest($webhook);

        return response()->json(['message' => 'Test event queued.', 'delivery_uuid' => $delivery->uuid, 'event_id' => $delivery->event_id], 202);
    }

    private function find(string $uuid): PlatformWebhook|JsonResponse
    {
        $teamId = $this->teamId();
        if ($teamId instanceof JsonResponse) {
            return $teamId;
        }

        return PlatformWebhook::query()->where('team_id', $teamId)->where('uuid', $uuid)->first()
            ?? response()->json(['message' => 'Webhook not found.'], 404);
    }

    private function teamId(): int|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! isPlatform()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return (int) $teamId;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlatformWebhook $webhook): array
    {
        return [
            'uuid' => $webhook->uuid,
            'url' => $webhook->url,
            'events' => $webhook->events,
            'description' => $webhook->description,
            'all_teams' => $webhook->all_teams,
            'enabled' => $webhook->enabled,
            'disabled_reason' => $webhook->disabled_reason,
            'last_response_status' => $webhook->last_response_status,
            'last_delivery_at' => $webhook->last_delivery_at?->toIso8601String(),
            'created_at' => $webhook->created_at?->toIso8601String(),
        ];
    }
}
