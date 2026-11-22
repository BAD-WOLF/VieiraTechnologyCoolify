<?php

namespace App\Services\Platform\Efi;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Efí Bank API client (OAuth 2.0 client credentials with the Client ID and Client Secret of an Efí
 * application, which works for accounts without CNPJ, "Efí Pro"):
 *
 * - API Cobranças (https://dev.efipay.com.br/docs/api-cobrancas): boleto with a Pix QR code
 *   ("Bolix"), its notifications (a token posted to the charge's notification_url) and its status.
 * - API Pix (https://dev.efipay.com.br/docs/api-pix), standard API of the Banco Central: charges
 *   with a due date ("cobv") and the webhook of the Pix key. Every call, the token included, goes
 *   with the certificate of the application (mutual TLS).
 */
class EfiClient
{
    public const API_CHARGES = 'charges';

    public const API_PIX = 'pix';

    private const DUE_CHARGES = '/v2/cobv';

    public function token(string $api, bool $fresh = false): string
    {
        $cacheKey = self::tokenCacheKey($api);
        if (! $fresh && is_string($cached = Cache::get($cacheKey)) && $cached !== '') {
            return $cached;
        }

        return (string) $this->authorize($api)['access_token'];
    }

    /**
     * Asks Efí for a new token of the API (the Pix one with the certificate) and keeps it.
     *
     * @return array{access_token: string, scope?: string, expires_in?: int} answer of Efí
     */
    public function authorize(string $api): array
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withBasicAuth((string) EfiSettings::value('client_id'), (string) EfiSettings::value('client_secret'))
            ->timeout((int) config('platform.efi.timeout', 30));
        $response = $api === self::API_PIX
            ? $request->withOptions($this->tlsOptions())->post(EfiSettings::pixUrl().'/oauth/token', ['grant_type' => 'client_credentials'])
            : $request->post(EfiSettings::chargesUrl().'/v1/authorize', ['grant_type' => 'client_credentials']);
        if ($response->failed() || blank($response->json('access_token'))) {
            throw EfiException::fromResponse($api === self::API_PIX ? 'Efí Pix authentication' : 'Efí Cobranças authentication', $response);
        }
        $answer = (array) $response->json();
        Cache::put(self::tokenCacheKey($api), (string) $answer['access_token'], max(60, (int) ($answer['expires_in'] ?? 600) - 60));

        return $answer;
    }

    public static function tokenCacheKey(string $api): string
    {
        return 'platform:efi:token:'.sha1(EfiSettings::environment().'|'.EfiSettings::value('client_id').'|'.$api);
    }

    public static function forgetTokens(): void
    {
        foreach ([self::API_CHARGES, self::API_PIX] as $api) {
            Cache::forget(self::tokenCacheKey($api));
        }
    }

    /**
     * Issues a boleto with a Pix QR code in one step.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> "data" of the answer (charge_id, barcode, pix, link, pdf...)
     */
    public function createBoleto(array $payload): array
    {
        $data = (array) $this->request(self::API_CHARGES, 'POST', '/v1/charge/one-step', $payload)->json('data');
        if (blank($data['charge_id'] ?? null)) {
            throw new EfiException('Efí did not return the charge id (charge_id).');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function charge(string $chargeId): array
    {
        return (array) $this->request(self::API_CHARGES, 'GET', '/v1/charge/'.rawurlencode($chargeId))->json('data');
    }

    public function cancelCharge(string $chargeId): void
    {
        $this->request(self::API_CHARGES, 'PUT', '/v1/charge/'.rawurlencode($chargeId).'/cancel');
    }

    /**
     * Marks a boleto as paid ("settled"), the way the sandbox confirms a payment.
     */
    public function settleCharge(string $chargeId): void
    {
        $this->request(self::API_CHARGES, 'PUT', '/v1/charge/'.rawurlencode($chargeId).'/settle');
    }

    /**
     * Changes of the charges behind a notification token (the POST Efí sends to notification_url).
     *
     * @return array<int, array<string, mixed>>
     */
    public function notification(string $token): array
    {
        return array_values(array_filter((array) $this->request(self::API_CHARGES, 'GET', '/v1/notification/'.rawurlencode($token))->json('data'), 'is_array'));
    }

    /**
     * Creates the Pix charge with a due date "txid" (26 to 35 letters and digits).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createDueCharge(string $txid, array $payload): array
    {
        $charge = (array) $this->request(self::API_PIX, 'PUT', self::DUE_CHARGES.'/'.rawurlencode($txid), $payload)->json();
        if (blank($charge['pixCopiaECola'] ?? null)) {
            throw new EfiException('Efí did not return the Pix code (pixCopiaECola).');
        }

        return $charge;
    }

    /**
     * @return array<string, mixed>
     */
    public function dueCharge(string $txid): array
    {
        return (array) $this->request(self::API_PIX, 'GET', self::DUE_CHARGES.'/'.rawurlencode($txid))->json();
    }

    public function cancelDueCharge(string $txid): void
    {
        $this->request(self::API_PIX, 'PATCH', self::DUE_CHARGES.'/'.rawurlencode($txid), ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR']);
    }

    /**
     * Pix notifications of the key go to "{url}/pix". Efí would only call a server that asks for
     * its client certificate; "x-skip-mtls-checking" turns that off, because the notifications
     * are authenticated by the secret token of the URL and their charges are always read back.
     */
    public function registerPixWebhook(string $pixKey, string $url): void
    {
        $this->request(self::API_PIX, 'PUT', '/v2/webhook/'.rawurlencode($pixKey), ['webhookUrl' => $url], headers: ['x-skip-mtls-checking' => 'true']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function request(string $api, string $method, string $path, array $payload = [], bool $retried = false, array $headers = []): Response
    {
        $url = ($api === self::API_PIX ? EfiSettings::pixUrl() : EfiSettings::chargesUrl()).$path;
        $request = $this->pending($api)->withHeaders($headers);
        $response = match ($method) {
            'GET' => $request->get($url, $payload),
            'POST' => $request->post($url, $payload),
            'PUT' => $request->put($url, $payload),
            'PATCH' => $request->patch($url, $payload),
            'DELETE' => $request->delete($url, $payload),
            default => throw new \InvalidArgumentException("Unsupported method {$method}"),
        };
        if ($response->status() === 401 && ! $retried) {
            $this->token($api, true);

            return $this->request($api, $method, $path, $payload, true, $headers);
        }
        if ($response->failed()) {
            throw EfiException::fromResponse("Efí {$method} {$path}", $response);
        }

        return $response;
    }

    private function pending(string $api): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withToken($this->token($api))
            ->timeout((int) config('platform.efi.timeout', 30))
            ->retry(2, 500, fn ($exception) => $exception instanceof \Illuminate\Http\Client\ConnectionException, throw: false);

        return $api === self::API_PIX ? $request->withOptions($this->tlsOptions()) : $request;
    }

    /**
     * @return array{cert: string, ssl_key: string}
     */
    private function tlsOptions(): array
    {
        return ['cert' => EfiSettings::certificatePath(), 'ssl_key' => EfiSettings::privateKeyPath()];
    }
}
