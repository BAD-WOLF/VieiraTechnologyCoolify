<?php

namespace App\Services\Platform\Efi;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\ConnectionException;

/**
 * Connection test of the Efí environment in use, one payment method at a time, in words the
 * operator can act on: what failed, why, and how to fix it.
 *
 * - Boleto (API Cobranças): the Client ID and the Client Secret.
 * - Pix (API Pix): the same credentials, the certificate of the environment (mutual TLS) and the
 *   scopes of the application.
 * - Payment notices (the webhook of the Pix key): only for a dashboard Efí can reach (public HTTPS);
 *   without them the open invoices are checked at Efí instead, so they never block the Pix.
 *
 * The last result of each environment is kept with a signature of the credentials it tested, so
 * the page shows the same state after a reload and says when the credentials changed since.
 */
final class EfiConnectionCheck
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const FAILED = 'failed';

    public const MISSING = 'missing';

    public const SKIPPED = 'skipped';

    /** Pix scopes of the charges with a due date, the only Pix charges the platform issues. */
    public const CHARGE_SCOPES = ['cobv.write', 'cobv.read'];

    /** Pix scopes of the webhook of the key. */
    public const WEBHOOK_SCOPES = ['webhook.write', 'webhook.read'];

    /**
     * @return array{environment: string, checked_at: string, signature: string, level: string, boleto: array<string, ?string>, pix: array<string, ?string>, webhook: array<string, ?string>}
     */
    public static function run(): array
    {
        $environment = EfiSettings::environment();
        $label = self::environmentLabel($environment);
        EfiClient::forgetTokens();
        $client = new EfiClient;
        $boleto = self::boleto($client, $label);
        [$pix, $scopes] = self::pix($client, $label, $boleto);
        $webhook = self::webhook($client, $label, $pix, $scopes);
        $result = [
            'environment' => $environment,
            'checked_at' => now()->toIso8601String(),
            'signature' => self::signature($environment),
            'boleto' => $boleto,
            'pix' => $pix,
            'webhook' => $webhook,
        ];
        $result['level'] = self::level($result);
        PlatformSetting::put(self::key($environment), json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $result;
    }

    /**
     * Last test of an environment, with "stale" when its credentials changed after it.
     *
     * @return array<string, mixed>|null
     */
    public static function last(string $environment): ?array
    {
        $environment = EfiSettings::normalize($environment);
        $stored = json_decode((string) PlatformSetting::get(self::key($environment)), true);
        if (! is_array($stored) || ! isset($stored['boleto'], $stored['pix'], $stored['webhook'])) {
            return null;
        }
        $stored['stale'] = ($stored['signature'] ?? null) !== self::signature($environment);

        return $stored;
    }

    public static function environmentLabel(string $environment): string
    {
        return EfiSettings::normalize($environment) === 'sandbox' ? 'Homologação' : 'Produção';
    }

    /**
     * Why Efí can not call this address, or null when it can (public host over HTTPS).
     */
    public static function unreachableReason(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || $host === 'localhost' || str_contains($host, '.') === false
            || preg_match('/\.(localhost|local|test|internal|lan|home\.arpa)$/', $host) === 1) {
            return __('a local address');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return __('a private address');
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return __('not HTTPS');
        }

        return null;
    }

    /**
     * @return array{status: string, title: string, detail: ?string, fix: ?string}
     */
    private static function boleto(EfiClient $client, string $label): array
    {
        if (! EfiSettings::isConfigured()) {
            return self::item(self::MISSING, __('Not configured: fill in the Client ID and the Client Secret of the application.'));
        }
        try {
            $client->authorize(EfiClient::API_CHARGES);

            return self::item(self::OK, __('Connected: invoices can be charged by boleto.'));
        } catch (EfiException $e) {
            return in_array($e->status, [401, 403], true)
                ? self::item(self::FAILED, __('Efí refused the Client ID and the Client Secret (HTTP :status).', ['status' => $e->status]), $e->getMessage(), self::credentialsFix($label))
                : self::failure($e, $label);
        } catch (ConnectionException $e) {
            return self::noAnswer($e);
        }
    }

    /**
     * @param  array{status: string}  $boleto
     * @return array{0: array{status: string, title: string, detail: ?string, fix: ?string}, 1: array<int, string>|null}
     */
    private static function pix(EfiClient $client, string $label, array $boleto): array
    {
        $missing = array_keys(array_filter([
            __('the Client ID') => blank(EfiSettings::value('client_id')),
            __('the Client Secret') => blank(EfiSettings::value('client_secret')),
            __('the certificate (.p12)') => blank(EfiSettings::pem('certificate')) || blank(EfiSettings::pem('private_key')),
            __('the Pix key') => is_null(EfiSettings::pixKey()),
        ]));
        if ($missing !== []) {
            return [self::item(self::MISSING, __('Not configured: to charge by Pix, fill in :items.', ['items' => implode(', ', $missing)])), null];
        }
        $certificate = EfiSettings::certificateInfo();
        $newCertificate = __('In Efí > API > Meus certificados, create a certificate of :env and send it here.', ['env' => $label]);
        if (! is_null($certificate) && $certificate['expired']) {
            return [self::item(self::FAILED, __('The certificate of :env expired on :date.', ['env' => $label, 'date' => $certificate['valid_to']->format('d/m/Y')]), null, $newCertificate), null];
        }
        if (! is_null($certificate) && $certificate['not_yet_valid']) {
            return [self::item(self::FAILED, __('The certificate of :env is only valid from :date.', ['env' => $label, 'date' => $certificate['valid_from']->format('d/m/Y H:i')]), null, $newCertificate), null];
        }
        try {
            $answer = $client->authorize(EfiClient::API_PIX);
        } catch (EfiException $e) {
            return [match (true) {
                is_null($e->status) => self::item(self::FAILED, $e->getMessage(), null, $newCertificate),
                $e->status === 401 => self::item(self::FAILED, __('Efí refused the Client ID and the Client Secret on the Pix API (HTTP :status).', ['status' => $e->status]), $e->getMessage(), self::credentialsFix($label)),
                $e->status === 403 => self::item(self::FAILED, __('Efí refused the certificate of :env (HTTP 403).', ['env' => $label]), $e->getMessage(), $boleto['status'] === self::OK
                    ? __('The Client ID and the Client Secret are right (the boleto connected), so the problem is the certificate: in Efí > API > Meus certificados, create a certificate of :env (one of Homologação does not work in Produção, nor the other way around) and send it here. If it already is of :env, check that the application has the Pix API enabled in :env.', ['env' => $label])
                    : __('Check that the certificate, the Client ID and the Client Secret are all of :env and of the same Efí account, and that the application has the Pix API enabled in :env.', ['env' => $label])),
                default => self::failure($e, $label),
            }, null];
        } catch (ConnectionException $e) {
            if (preg_match('/cURL error (35|58|59|66|80|83)\b|SSL|certificate|private key/i', $e->getMessage()) === 1) {
                return [self::item(self::FAILED, __('The certificate could not be used to connect to Efí.'), $e->getMessage(),
                    __('Send the certificate (.p12) of :env again; if it has a password, fill it in too.', ['env' => $label])), null];
            }

            return [self::noAnswer($e), null];
        } catch (\RuntimeException $e) {
            return [self::item(self::FAILED, $e->getMessage(), null, $newCertificate), null];
        }
        $scopes = is_string($answer['scope'] ?? null) ? array_values(array_filter(preg_split('/\s+/', $answer['scope']) ?: [])) : null;
        $absent = is_null($scopes) ? [] : array_values(array_diff(self::CHARGE_SCOPES, $scopes));
        if ($absent !== []) {
            return [self::item(self::FAILED, __('The application can not create Pix charges: the scopes :scopes are not enabled.', ['scopes' => implode(', ', $absent)]), null,
                __('In Efí > API > Aplicações > your application > Editar, enable in the Pix API the "Cobranças com vencimento" (cobv.write and cobv.read) of :env, then test again.', ['env' => $label])), $scopes];
        }
        $detail = is_null($certificate) ? null : __('Certificate issued to :subject, valid until :date.', ['subject' => $certificate['subject'], 'date' => $certificate['valid_to']->format('d/m/Y')]);

        return [self::item(self::OK, __('Connected: invoices can be charged by Pix.'), $detail), $scopes];
    }

    /**
     * @param  array{status: string}  $pix
     * @param  array<int, string>|null  $scopes
     * @return array{status: string, title: string, detail: ?string, fix: ?string}
     */
    private static function webhook(EfiClient $client, string $label, array $pix, ?array $scopes): array
    {
        if ($pix['status'] !== self::OK) {
            return self::item(self::SKIPPED, __('Payment notices wait for the Pix connection.'));
        }
        $meanwhile = __('Meanwhile the open invoices are checked at Efí every 15 minutes, and every 20 seconds while the customer looks at one.');
        $url = EfiSettings::webhookUrl();
        if (! is_null($reason = self::unreachableReason($url))) {
            return self::item(self::SKIPPED, __('Payment notices are off on this address.'),
                __('Efí only notifies payments to a public HTTPS address, and this dashboard is at :url (:reason).', ['url' => rtrim(base_url(), '/'), 'reason' => $reason]),
                __('Nothing to do while testing.').' '.$meanwhile.' '.__('In production, with the public domain, "Save and connect" registers the notices.'));
        }
        $absent = is_null($scopes) ? [] : array_values(array_diff(self::WEBHOOK_SCOPES, $scopes));
        if ($absent !== []) {
            return self::item(self::WARNING, __('Payment notices are off: the scopes :scopes are not enabled.', ['scopes' => implode(', ', $absent)]), null,
                __('In Efí > API > Aplicações > your application > Editar, enable the "Webhooks" (webhook.write and webhook.read) of the Pix API in :env.', ['env' => $label]).' '.$meanwhile);
        }
        try {
            $client->registerPixWebhook((string) EfiSettings::pixKey(), $url);
        } catch (EfiException $e) {
            return self::item(self::WARNING, __('Efí did not accept the address of the payment notices.'), $e->getMessage(),
                __('Check that :host answers over HTTPS from the internet (a valid certificate, no firewall in front of it) and test again.', ['host' => (string) parse_url($url, PHP_URL_HOST)]).' '.$meanwhile);
        } catch (ConnectionException $e) {
            return self::item(self::WARNING, __('No answer from Efí.'), $e->getMessage(), $meanwhile);
        }
        PlatformSetting::put('efi.webhook_registered_at', now()->toIso8601String());
        PlatformSetting::put('efi.webhook_registered_url', $url);
        audit_log('platform.efi.webhook_registered', null, ['url' => preg_replace('#/[^/]+$#', '/***', $url)], 0);

        return self::item(self::OK, __('Payment notices registered: Efí warns as soon as a Pix is paid.'), preg_replace('#/[^/]+$#', '/•••', $url).'/pix');
    }

    /**
     * ok: what is configured works; warning: something configured failed while a method works;
     * failed: no method works.
     *
     * @param  array{boleto: array{status: string}, pix: array{status: string}, webhook: array{status: string}}  $result
     */
    private static function level(array $result): string
    {
        $methods = [$result['boleto']['status'], $result['pix']['status']];
        if (! in_array(self::OK, $methods, true)) {
            return self::FAILED;
        }

        return in_array(self::FAILED, $methods, true) || in_array($result['webhook']['status'], [self::WARNING, self::FAILED], true) ? self::WARNING : self::OK;
    }

    /**
     * Fingerprint of what a test depends on, never the secrets themselves.
     */
    private static function signature(string $environment): string
    {
        return hash('sha256', implode('|', [
            $environment,
            (string) EfiSettings::credential($environment, 'client_id'),
            (string) EfiSettings::credential($environment, 'client_secret'),
            (string) EfiSettings::pem('certificate', $environment),
            (string) EfiSettings::pixKey($environment),
            rtrim(base_url(), '/'),
        ]));
    }

    private static function key(string $environment): string
    {
        return "efi.{$environment}.last_check";
    }

    private static function credentialsFix(string $label): string
    {
        return __('Copy them again from Efí > API > Aplicações > your application, in the :env tab. Credentials of Homologação do not work in Produção, nor the other way around.', ['env' => $label]);
    }

    /**
     * @return array{status: string, title: string, detail: ?string, fix: ?string}
     */
    private static function failure(EfiException $e, string $label): array
    {
        return self::item(self::FAILED, __('Efí answered with an error (HTTP :status).', ['status' => $e->status ?? '?']), $e->getMessage(),
            __('Try again in a few minutes. If it goes on, send the detail below to the support of Efí.'));
    }

    /**
     * @return array{status: string, title: string, detail: ?string, fix: ?string}
     */
    private static function noAnswer(ConnectionException $e): array
    {
        return self::item(self::FAILED, __('No answer from Efí.'), $e->getMessage(), __('Check the internet connection of the server and try again.'));
    }

    /**
     * @return array{status: string, title: string, detail: ?string, fix: ?string}
     */
    private static function item(string $status, string $title, ?string $detail = null, ?string $fix = null): array
    {
        return ['status' => $status, 'title' => $title, 'detail' => $detail, 'fix' => $fix];
    }
}
