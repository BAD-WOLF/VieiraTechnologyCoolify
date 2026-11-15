<?php

namespace App\Jobs;

use App\Models\PlatformWebhook;
use App\Models\PlatformWebhookDelivery;
use App\Services\Platform\Webhooks;
use App\Services\Platform\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * POSTs one event to one webhook. Retries with backoff on network errors, 408, 429 and 5xx;
 * other answers fail at once. A webhook that keeps failing is disabled.
 */
class PlatformWebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 8;

    public $timeout = 60;

    public function __construct(public int $deliveryId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600, 7200, 21600];
    }

    public function handle(): void
    {
        $delivery = PlatformWebhookDelivery::query()->with('webhook')->find($this->deliveryId);
        if (! $delivery || $delivery->status === PlatformWebhookDelivery::STATUS_SUCCEEDED) {
            return;
        }
        $webhook = $delivery->webhook;
        if (! $webhook instanceof PlatformWebhook || ! $webhook->enabled) {
            $delivery->update(['status' => PlatformWebhookDelivery::STATUS_FAILED, 'error' => 'Webhook disabled.']);

            return;
        }
        try {
            $target = WebhookUrlGuard::target($webhook->url, Webhooks::allowsPrivateTargets((int) $webhook->team_id));
        } catch (\InvalidArgumentException $e) {
            $this->finish($delivery, $webhook, false, null, $e->getMessage());

            return;
        }

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();
        $delivery->increment('attempts');
        $address = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
        try {
            $response = Http::timeout((int) config('platform.webhooks.timeout', 10))
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$address}"]]])
                ->withHeaders([
                    'User-Agent' => config('platform.name', 'Hostrema').'-Webhooks/1.0',
                    'X-Hostrema-Event' => $delivery->event_type,
                    'X-Hostrema-Delivery' => $delivery->uuid,
                    'X-Hostrema-Signature' => Webhooks::signature((string) $webhook->secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);
        } catch (\Throwable $e) {
            $delivery->update(['error' => str($e->getMessage())->limit(480)->value()]);

            throw new \RuntimeException('Webhook delivery failed: '.$e->getMessage(), 0, $e);
        }

        $status = $response->status();
        $excerpt = str((string) $response->body())->limit(480)->value();
        if ($response->successful()) {
            $this->finish($delivery, $webhook, true, $status, null, $excerpt);

            return;
        }
        $delivery->update(['response_status' => $status, 'response_excerpt' => $excerpt, 'error' => "HTTP {$status}"]);
        if (in_array($status, [408, 429], true) || $status >= 500) {
            throw new \RuntimeException("Webhook answered HTTP {$status}.");
        }
        $this->finish($delivery, $webhook, false, $status, "HTTP {$status}", $excerpt);
    }

    public function failed(?\Throwable $exception): void
    {
        $delivery = PlatformWebhookDelivery::query()->with('webhook')->find($this->deliveryId);
        if ($delivery && $delivery->webhook && $delivery->status !== PlatformWebhookDelivery::STATUS_SUCCEEDED) {
            $this->finish($delivery, $delivery->webhook, false, $delivery->response_status, $delivery->error ?? $exception?->getMessage());
        }
    }

    private function finish(PlatformWebhookDelivery $delivery, PlatformWebhook $webhook, bool $succeeded, ?int $status, ?string $error, ?string $excerpt = null): void
    {
        $delivery->update([
            'status' => $succeeded ? PlatformWebhookDelivery::STATUS_SUCCEEDED : PlatformWebhookDelivery::STATUS_FAILED,
            'response_status' => $status,
            'response_excerpt' => $excerpt ?? $delivery->response_excerpt,
            'error' => $succeeded ? null : str((string) $error)->limit(480)->value(),
            'delivered_at' => $succeeded ? now() : $delivery->delivered_at,
        ]);
        $failures = $succeeded ? 0 : $webhook->consecutive_failures + 1;
        $updates = ['consecutive_failures' => $failures, 'last_response_status' => $status, 'last_delivery_at' => now()];
        $limit = (int) config('platform.webhooks.disable_after_failures', 50);
        if (! $succeeded && $limit > 0 && $failures >= $limit) {
            $updates['enabled'] = false;
            $updates['disabled_reason'] = __('Disabled after :count failed deliveries in a row.', ['count' => $failures]);
            audit_log('webhook.disabled', $webhook, ['failures' => $failures], (int) $webhook->team_id, 'system');
        }
        $webhook->forceFill($updates)->save();
    }
}
