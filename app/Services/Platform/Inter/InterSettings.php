<?php

namespace App\Services\Platform\Inter;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Banco Inter credentials: values saved in the operator console (encrypted in platform_settings)
 * take precedence over the INTER_* environment variables.
 */
class InterSettings
{
    public const PRODUCTION_URL = 'https://cdpj.partners.bancointer.com.br';

    public const SANDBOX_URL = 'https://cdpj-sandbox.partners.uatinter.co';

    public const KEYS = ['environment', 'client_id', 'client_secret', 'certificate', 'private_key', 'conta_corrente', 'webhook_token'];

    public static function value(string $key): ?string
    {
        $stored = PlatformSetting::get("inter.{$key}");
        if (filled($stored)) {
            return (string) $stored;
        }
        $configured = config("platform.inter.{$key}");

        return filled($configured) ? (string) $configured : null;
    }

    public static function store(string $key, ?string $value): void
    {
        PlatformSetting::put("inter.{$key}", $value);
    }

    public static function environment(): string
    {
        return self::value('environment') === 'sandbox' ? 'sandbox' : 'production';
    }

    public static function isSandbox(): bool
    {
        return self::environment() === 'sandbox';
    }

    public static function baseUrl(): string
    {
        return self::isSandbox() ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    public static function isConfigured(): bool
    {
        return filled(self::value('client_id')) && filled(self::value('client_secret'))
            && filled(self::pem('certificate')) && filled(self::pem('private_key'));
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

    public static function certificatePath(): string
    {
        return self::materialize('certificate');
    }

    public static function privateKeyPath(): string
    {
        return self::materialize('private_key');
    }

    /**
     * Guzzle needs files for mTLS: the PEM is written once (0600) under storage and rewritten
     * whenever it changes or the container is recreated.
     */
    private static function materialize(string $key): string
    {
        $pem = self::pem($key);
        if (is_null($pem)) {
            throw new InterException("Banco Inter {$key} is not configured or is not a valid PEM.");
        }
        $directory = storage_path('app/private/platform/inter');
        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory, 0700);
        }
        $path = $directory.'/'.$key.'-'.substr(hash('sha256', $pem), 0, 24).'.pem';
        if (! is_file($path)) {
            file_put_contents($path, $pem, LOCK_EX);
            chmod($path, 0600);
        }

        return $path;
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

    public static function webhookUrl(): string
    {
        return rtrim(base_url(), '/').'/api/v1/billing/webhooks/inter/'.self::webhookToken();
    }
}
