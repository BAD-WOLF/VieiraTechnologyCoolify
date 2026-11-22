<?php

namespace App\Services\Platform\Efi;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Efí Bank credentials: values saved in the operator console (encrypted in platform_settings)
 * take precedence over the EFI_* environment variables.
 *
 * The Cobranças API (boleto with Pix) needs only the Client ID and Client Secret of the Efí
 * application; the Pix API also needs the certificate of the application (mutual TLS) and a Pix
 * key of the account.
 */
class EfiSettings
{
    public const PIX_PRODUCTION_URL = 'https://pix.api.efipay.com.br';

    public const PIX_SANDBOX_URL = 'https://pix-h.api.efipay.com.br';

    public const CHARGES_PRODUCTION_URL = 'https://cobrancas.api.efipay.com.br';

    public const CHARGES_SANDBOX_URL = 'https://cobrancas-h.api.efipay.com.br';

    public const KEYS = ['environment', 'client_id', 'client_secret', 'certificate', 'private_key', 'pix_key', 'webhook_token'];

    public static function value(string $key): ?string
    {
        $stored = PlatformSetting::get("efi.{$key}");
        if (filled($stored)) {
            return (string) $stored;
        }
        $configured = config("platform.efi.{$key}");

        return filled($configured) ? (string) $configured : null;
    }

    public static function store(string $key, ?string $value): void
    {
        PlatformSetting::put("efi.{$key}", $value);
    }

    public static function environment(): string
    {
        return self::value('environment') === 'sandbox' ? 'sandbox' : 'production';
    }

    public static function isSandbox(): bool
    {
        return self::environment() === 'sandbox';
    }

    public static function pixUrl(): string
    {
        return self::isSandbox() ? self::PIX_SANDBOX_URL : self::PIX_PRODUCTION_URL;
    }

    public static function chargesUrl(): string
    {
        return self::isSandbox() ? self::CHARGES_SANDBOX_URL : self::CHARGES_PRODUCTION_URL;
    }

    /**
     * Boletos can be issued (Cobranças API).
     */
    public static function isConfigured(): bool
    {
        return filled(self::value('client_id')) && filled(self::value('client_secret'));
    }

    /**
     * Pix charges can be issued (Pix API: certificate and Pix key).
     */
    public static function pixReady(): bool
    {
        return self::isConfigured() && filled(self::pixKey()) && filled(self::pem('certificate')) && filled(self::pem('private_key'));
    }

    /**
     * Certificate or key as PEM, accepting a PEM text, a base64 encoded PEM or a file path.
     */
    public static function pem(string $key): ?string
    {
        $value = trim((string) self::value($key));
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, '-----BEGIN')) {
            return $value."\n";
        }
        if (strlen($value) < 4096 && ! str_contains($value, "\n") && is_file($value) && is_readable($value)) {
            $contents = trim((string) file_get_contents($value));

            return str_starts_with($contents, '-----BEGIN') ? $contents."\n" : null;
        }
        $decoded = base64_decode(preg_replace('/\s+/', '', $value) ?? '', true);
        if ($decoded !== false && str_starts_with(trim($decoded), '-----BEGIN')) {
            return trim($decoded)."\n";
        }

        return null;
    }

    /**
     * The certificate Efí gives for an application (.p12, or the .pem converted from it with both
     * the certificate and the key) as [certificate, key] in PEM, the key without a password.
     *
     * The .p12 files of Efí use algorithms OpenSSL 3 only reads with its "legacy" provider, which
     * PHP does not load: those are opened with the openssl command (option -legacy).
     *
     * @return array{0: string, 1: string}
     *
     * @throws EfiException when it can not be read
     */
    public static function readCertificate(string $contents, string $password = ''): array
    {
        if (str_contains($contents, '-----BEGIN')) {
            [$certificates, $keys] = self::pemBlocks($contents);
            if ($certificates === [] || $keys === []) {
                throw new EfiException(__('The PEM file must have the certificate and the private key of the Efí application.'));
            }
        } else {
            $bundle = [];
            if (! @openssl_pkcs12_read($contents, $bundle, $password)) {
                $bundle = self::readLegacyPkcs12($contents, $password);
            }
            if (blank($bundle['cert'] ?? null) || blank($bundle['pkey'] ?? null)) {
                throw new EfiException(__('The .p12 certificate could not be read. Check its password, or convert it with "openssl pkcs12 -in certificate.p12 -out certificate.pem -nodes -legacy -passin pass:" and send the .pem file.'));
            }
            $certificates = array_values(array_filter([$bundle['cert'], ...array_values((array) ($bundle['extracerts'] ?? []))], 'is_string'));
            $keys = [(string) $bundle['pkey']];
        }

        $key = @openssl_pkey_get_private($keys[0], $password);
        if ($key === false || ! @openssl_pkey_export($key, $keyPem)) {
            throw new EfiException(__('The private key could not be read. Check the password of the certificate.'));
        }
        foreach ($certificates as $certificate) {
            if (@openssl_x509_read($certificate) !== false && @openssl_x509_check_private_key($certificate, $keyPem)) {
                return [trim($certificate)."\n", trim((string) $keyPem)."\n"];
            }
        }

        throw new EfiException(__('The private key does not belong to the certificate.'));
    }

    /**
     * Certificates and private keys of a PEM text.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function pemBlocks(string $contents): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $contents, $certificates);
        preg_match_all('/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----.+?-----END (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/s', $contents, $keys);

        return [$certificates[0], $keys[0]];
    }

    /**
     * @return array{cert?: string, pkey?: string, extracerts?: array<int, string>}
     */
    private static function readLegacyPkcs12(string $contents, string $password): array
    {
        $binary = (new ExecutableFinder)->find('openssl');
        if (is_null($binary)) {
            return [];
        }
        $input = tempnam(sys_get_temp_dir(), 'efi-p12-');
        if ($input === false) {
            return [];
        }
        try {
            file_put_contents($input, $contents);
            // The password goes through the environment, never on the command line.
            $process = new Process([$binary, 'pkcs12', '-legacy', '-in', $input, '-nodes', '-passin', 'env:EFI_CERTIFICATE_PASSWORD'], null, ['EFI_CERTIFICATE_PASSWORD' => $password], null, 20);
            $process->run();
            if (! $process->isSuccessful()) {
                return [];
            }
            [$certificates, $keys] = self::pemBlocks($process->getOutput());
            if ($certificates === [] || $keys === []) {
                return [];
            }

            return ['cert' => $certificates[0], 'pkey' => $keys[0], 'extracerts' => array_slice($certificates, 1)];
        } finally {
            @unlink($input);
        }
    }

    public static function certificatePath(): string
    {
        return self::materialize('certificate');
    }

    public static function privateKeyPath(): string
    {
        return self::materialize('private_key');
    }

    /**
     * Guzzle needs files for mutual TLS: the PEM is written once (0600) under storage and
     * rewritten whenever it changes or the container is recreated.
     */
    private static function materialize(string $key): string
    {
        $pem = self::pem($key);
        if (is_null($pem)) {
            throw new EfiException(__('The certificate of the Efí application is not configured.'));
        }
        $directory = storage_path('app/private/platform/efi');
        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory, 0700);
        }
        $path = $directory.'/'.$key.'-'.substr(hash('sha256', $pem), 0, 24).'.pem';
        if (! is_file($path)) {
            // Written to a private temporary file (0600) first, so the key is never readable by others.
            $temporary = tempnam($directory, $key.'-');
            if ($temporary === false) {
                throw new EfiException(__('The certificate of the Efí application is not configured.'));
            }
            chmod($temporary, 0600);
            file_put_contents($temporary, $pem, LOCK_EX);
            rename($temporary, $path);
            // Certificates and keys replaced in the console do not stay on the disk.
            foreach (glob($directory.'/'.$key.'-*.pem') ?: [] as $old) {
                if ($old !== $path) {
                    @unlink($old);
                }
            }
        }

        return $path;
    }

    /**
     * Pix key of the Efí account (CPF/CNPJ, e-mail, phone or random key) that receives the Pix.
     */
    public static function pixKey(): ?string
    {
        $key = trim((string) self::value('pix_key'));

        return $key !== '' ? $key : null;
    }

    public static function webhookToken(): string
    {
        $token = self::value('webhook_token');
        if (blank($token)) {
            $token = Str::random(48);
            self::store('webhook_token', $token);
        }

        return $token;
    }

    /**
     * Address of the Pix notifications: Efí calls it with "/pix" added at the end.
     */
    public static function webhookUrl(): string
    {
        return rtrim(base_url(), '/').'/api/v1/billing/webhooks/efi/'.self::webhookToken();
    }

    /**
     * Address Efí calls with a notification token whenever a boleto changes.
     */
    public static function notificationUrl(): string
    {
        return self::webhookUrl().'/charges';
    }
}
