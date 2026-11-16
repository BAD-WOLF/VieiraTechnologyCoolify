<?php

namespace App\Services\Platform\Inter;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Banco Inter API client (OAuth 2.0 client credentials + mTLS):
 * - "Cobrança" v3, boleto + Pix ("Bolepix"): https://developers.inter.co/references/cobranca-bolepix
 * - Pix (standard API of the Banco Central), charges with a due date ("cobv") and their webhook:
 *   https://developers.inter.co/references/pix
 * Each API has its own scopes and token, so an application with only one of them still works.
 */
class InterClient
{
    public const SCOPES = 'boleto-cobranca.read boleto-cobranca.write';

    public const PIX_SCOPES = 'cobv.write cobv.read webhook.write webhook.read pix.read';

    private const CHARGES = '/cobranca/v3/cobrancas';

    private const DUE_CHARGES = '/pix/v2/cobv';

    public function token(bool $fresh = false, string $scopes = self::SCOPES): string
    {
        $cacheKey = self::tokenCacheKey($scopes);
        if (! $fresh && is_string($cached = Cache::get($cacheKey)) && $cached !== '') {
            return $cached;
        }
        $response = Http::asForm()
            ->acceptJson()
            ->withOptions($this->tlsOptions())
            ->timeout((int) config('platform.inter.timeout', 30))
            ->post(InterSettings::baseUrl().'/oauth/v2/token', [
                'client_id' => InterSettings::value('client_id'),
                'client_secret' => InterSettings::value('client_secret'),
                'grant_type' => 'client_credentials',
                'scope' => $scopes,
            ]);
        if ($response->failed() || blank($response->json('access_token'))) {
            throw InterException::fromResponse('Banco Inter authentication', $response);
        }
        $token = (string) $response->json('access_token');
        Cache::put($cacheKey, $token, max(60, (int) $response->json('expires_in', 3600) - 120));

        return $token;
    }

    public static function tokenCacheKey(string $scopes = self::SCOPES): string
    {
        return 'platform:inter:token:'.sha1(InterSettings::environment().'|'.InterSettings::value('client_id').'|'.$scopes);
    }

    public static function forgetTokens(): void
    {
        foreach ([self::SCOPES, self::PIX_SCOPES] as $scopes) {
            Cache::forget(self::tokenCacheKey($scopes));
        }
    }

    /**
     * Issues a charge (boleto + Pix). Returns the "codigoSolicitacao" of the charge.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createCharge(array $payload): string
    {
        $code = $this->request('POST', self::CHARGES, $payload)->json('codigoSolicitacao');
        if (blank($code)) {
            throw new InterException('Banco Inter did not return the charge code (codigoSolicitacao).');
        }

        return (string) $code;
    }

    /**
     * @return array{cobranca?: array<string, mixed>, boleto?: array<string, mixed>, pix?: array<string, mixed>}
     */
    public function charge(string $code): array
    {
        return (array) $this->request('GET', self::CHARGES.'/'.rawurlencode($code))->json();
    }

    /**
     * PDF of the boleto (binary).
     */
    public function chargePdf(string $code): string
    {
        $pdf = base64_decode((string) $this->request('GET', self::CHARGES.'/'.rawurlencode($code).'/pdf')->json('pdf'), true);
        if ($pdf === false || $pdf === '') {
            throw new InterException('Banco Inter returned an empty PDF.');
        }

        return $pdf;
    }

    public function cancelCharge(string $code, string $reason = 'APEDIDODOCLIENTE'): void
    {
        $this->request('POST', self::CHARGES.'/'.rawurlencode($code).'/cancelar', ['motivoCancelamento' => $reason]);
    }

    /**
     * Sandbox only: marks a charge as paid by "BOLETO" or "PIX".
     */
    public function simulatePayment(string $code, string $method = 'PIX'): void
    {
        if (! InterSettings::isSandbox()) {
            throw new InterException('Payments can only be simulated in the Inter sandbox.');
        }
        $this->request('POST', self::CHARGES.'/'.rawurlencode($code).'/pagar', ['pagarCom' => $method === 'BOLETO' ? 'BOLETO' : 'PIX']);
    }

    public function registerWebhook(string $url): void
    {
        $this->request('PUT', self::CHARGES.'/webhook', ['webhookUrl' => $url]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function webhook(): ?array
    {
        try {
            return (array) $this->request('GET', self::CHARGES.'/webhook')->json();
        } catch (InterException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Creates the Pix charge with a due date "txid" (26 to 35 letters and digits).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createDueCharge(string $txid, array $payload): array
    {
        $charge = (array) $this->request('PUT', self::DUE_CHARGES.'/'.rawurlencode($txid), $payload, scopes: self::PIX_SCOPES)->json();
        if (blank($charge['pixCopiaECola'] ?? null)) {
            throw new InterException('Banco Inter did not return the Pix code (pixCopiaECola).');
        }

        return $charge;
    }

    /**
     * @return array<string, mixed>
     */
    public function dueCharge(string $txid): array
    {
        return (array) $this->request('GET', self::DUE_CHARGES.'/'.rawurlencode($txid), scopes: self::PIX_SCOPES)->json();
    }

    public function cancelDueCharge(string $txid): void
    {
        $this->request('PATCH', self::DUE_CHARGES.'/'.rawurlencode($txid), ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR'], scopes: self::PIX_SCOPES);
    }

    /**
     * Pix notifications of a key go to "{url}/pix" (standard of the Banco Central).
     */
    public function registerPixWebhook(string $pixKey, string $url): void
    {
        $this->request('PUT', '/pix/v2/webhook/'.rawurlencode($pixKey), ['webhookUrl' => $url], scopes: self::PIX_SCOPES);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function request(string $method, string $path, array $payload = [], bool $retried = false, string $scopes = self::SCOPES): Response
    {
        $url = InterSettings::baseUrl().$path;
        $request = $this->pending($scopes);
        $response = match ($method) {
            'GET' => $request->get($url, $payload),
            'POST' => $request->post($url, $payload),
            'PUT' => $request->put($url, $payload),
            'PATCH' => $request->patch($url, $payload),
            'DELETE' => $request->delete($url, $payload),
            default => throw new \InvalidArgumentException("Unsupported method {$method}"),
        };
        if ($response->status() === 401 && ! $retried) {
            $this->token(true, $scopes);

            return $this->request($method, $path, $payload, true, $scopes);
        }
        if ($response->failed()) {
            throw InterException::fromResponse("Banco Inter {$method} {$path}", $response);
        }

        return $response;
    }

    private function pending(string $scopes): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withOptions($this->tlsOptions())
            ->withToken($this->token(false, $scopes))
            ->timeout((int) config('platform.inter.timeout', 30))
            ->retry(2, 500, fn ($exception) => $exception instanceof \Illuminate\Http\Client\ConnectionException, throw: false);
        $account = InterSettings::value('conta_corrente');
        if (filled($account)) {
            $request->withHeaders(['x-conta-corrente' => preg_replace('/\D+/', '', $account)]);
        }

        return $request;
    }

    /**
     * @return array{cert: string, ssl_key: string}
     */
    private function tlsOptions(): array
    {
        return ['cert' => InterSettings::certificatePath(), 'ssl_key' => InterSettings::privateKeyPath()];
    }
}
