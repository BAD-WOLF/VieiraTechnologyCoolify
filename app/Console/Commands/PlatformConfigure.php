<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\Efi\EfiClient;
use App\Services\Platform\Efi\EfiException;
use App\Services\Platform\Efi\EfiSettings;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\PlatformConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Configures what the owner would otherwise type in the panel, for the installers
 * (infra/prod/hostrema-prod.sh): settings, transactional e-mail, Efí Bank, the SSH key the
 * dashboard uses on the nodes and an API token for Ansible.
 *
 * Secrets never go on the command line: they come as JSON on the standard input (--stdin):
 *
 *   {"settings": {"platform.base_domain": "apps.example.com"},
 *    "smtp": {"host": "...", "port": 587, "encryption": "starttls", "username": "...", "password": "...",
 *             "from_address": "...", "from_name": "..."},
 *    "efi": {"environment": "production", "client_id": "...", "client_secret": "...", "pix_key": "...",
 *            "certificate_base64": "<.p12 or .pem>", "certificate_password": ""},
 *    "node_key": true, "api_token": "ansible"}
 *
 * Safe to run again: the same input leaves everything as it is (an API token is the exception:
 * a new one replaces the older token of the same name).
 */
class PlatformConfigure extends Command
{
    public const NODE_KEY = 'Hostrema nodes';

    protected $signature = 'platform:configure
        {--stdin : Read the configuration (JSON) from the standard input}
        {--file= : Read the configuration (JSON) from this file}
        {--set=* : Setting of the panel as path=value (e.g. platform.base_domain=apps.example.com)}
        {--node-key : Create (once) the SSH key of the nodes and print it}
        {--api-token= : Create an API token (read and write) of the owner with this name}
        {--json : Print the result as JSON}';

    protected $description = 'Configure the platform without the panel (settings, e-mail, Efí Bank, node key, API token)';

    public function handle(): int
    {
        if (! isPlatform()) {
            $this->error('The platform is off. Set PLATFORM_ENABLED=true in the environment and run again.');

            return self::FAILURE;
        }
        $input = [];
        if ($this->option('stdin') || filled($this->option('file'))) {
            $file = (string) $this->option('file');
            if ($file !== '' && ! is_readable($file)) {
                $this->error("Can not read {$file}.");

                return self::FAILURE;
            }
            $raw = $file !== '' ? file_get_contents($file) : stream_get_contents(STDIN);
            $input = json_decode((string) $raw, true);
            if (! is_array($input)) {
                $this->error('The configuration is not a JSON object.');

                return self::FAILURE;
            }
        }
        foreach ((array) $this->option('set') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                $this->error("--set needs path=value ({$pair}).");

                return self::FAILURE;
            }
            [$path, $value] = explode('=', (string) $pair, 2);
            $input['settings'][trim($path)] = $value;
        }
        if ($this->option('node-key')) {
            $input['node_key'] = true;
        }
        if (filled($this->option('api-token'))) {
            $input['api_token'] = (string) $this->option('api-token');
        }

        $result = [];
        $errors = [];
        PlatformConfig::apply();
        if (! empty($input['settings']) && is_array($input['settings'])) {
            [$result['settings'], $settingErrors] = $this->applySettings($input['settings']);
            $errors = array_merge($errors, $settingErrors);
        }
        if (! empty($input['smtp']) && is_array($input['smtp'])) {
            [$result['smtp'], $smtpErrors] = $this->configureSmtp($input['smtp']);
            $errors = array_merge($errors, $smtpErrors);
        }
        if (! empty($input['efi']) && is_array($input['efi'])) {
            [$result['efi'], $efiErrors] = $this->configureEfi($input['efi']);
            $errors = array_merge($errors, $efiErrors);
        }
        if (! empty($input['node_key'])) {
            $result['node_key'] = $this->nodeKey();
        }
        if (! empty($input['api_token'])) {
            $token = $this->apiToken((string) $input['api_token']);
            if (is_null($token)) {
                $errors[] = 'There is no owner yet: run platform:install first.';
            } else {
                $result['api_token'] = $token;
            }
        }
        $result['errors'] = $errors;

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($result as $section => $value) {
                if ($section === 'errors') {
                    continue;
                }
                $this->line($section.': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $value));
            }
            foreach ($errors as $error) {
                $this->error($error);
            }
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function applySettings(array $settings): array
    {
        $applied = [];
        $errors = [];
        foreach ($settings as $path => $raw) {
            $path = (string) $path;
            if (is_null(PlatformConfig::definition($path))) {
                $errors[] = "Unknown setting {$path}.";

                continue;
            }
            $rawValue = is_bool($raw) ? ($raw ? '1' : '0') : $raw;
            $validator = Validator::make(['value' => $rawValue], ['value' => PlatformConfig::rules($path)]);
            if ($validator->fails()) {
                $errors[] = "{$path}: ".$validator->errors()->first('value');

                continue;
            }
            try {
                PlatformConfig::set($path, PlatformConfig::normalize($path, $rawValue));
                $applied[$path] = 'saved';
            } catch (\Throwable $e) {
                $errors[] = "{$path}: ".$e->getMessage();
            }
        }
        if ($applied !== []) {
            audit_log('platform.settings.updated', null, ['paths' => array_keys($applied), 'source' => 'platform:configure'], 0, 'system');
        }

        return [$applied, $errors];
    }

    /**
     * @param  array<string, mixed>  $smtp
     * @return array{0: string, 1: list<string>}
     */
    private function configureSmtp(array $smtp): array
    {
        $validator = Validator::make($smtp, [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['nullable', 'in:starttls,tls,none'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return ['not configured', array_map(fn ($message) => "smtp: {$message}", $validator->errors()->all())];
        }
        $settings = InstanceSettings::query()->firstOrCreate(['id' => 0]);
        $settings->forceFill([
            'smtp_enabled' => true,
            'smtp_host' => (string) $smtp['host'],
            'smtp_port' => (int) $smtp['port'],
            'smtp_encryption' => (string) ($smtp['encryption'] ?? 'starttls'),
            'smtp_username' => filled($smtp['username'] ?? null) ? (string) $smtp['username'] : null,
            'smtp_password' => filled($smtp['password'] ?? null) ? (string) $smtp['password'] : null,
            'smtp_from_address' => strtolower((string) $smtp['from_address']),
            'smtp_from_name' => (string) ($smtp['from_name'] ?? config('platform.name')),
            'resend_enabled' => false,
        ])->save();
        audit_log('platform.email.configured', null, ['host' => (string) $smtp['host'], 'source' => 'platform:configure'], 0, 'system');

        return ['configured', []];
    }

    /**
     * @param  array<string, mixed>  $efi
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function configureEfi(array $efi): array
    {
        $validator = Validator::make($efi, [
            'environment' => ['nullable', 'in:production,sandbox'],
            'client_id' => ['required', 'string', 'max:200'],
            'client_secret' => ['required', 'string', 'max:500'],
            'pix_key' => ['nullable', 'string', 'max:77'],
            'certificate_base64' => ['nullable', 'string'],
            'certificate_password' => ['nullable', 'string', 'max:200'],
        ]);
        if ($validator->fails()) {
            return [['configured' => false], array_map(fn ($message) => "efi: {$message}", $validator->errors()->all())];
        }
        $pem = null;
        if (filled($efi['certificate_base64'] ?? null)) {
            $contents = base64_decode(preg_replace('/\s+/', '', (string) $efi['certificate_base64']) ?? '', true);
            if ($contents === false || $contents === '') {
                return [['configured' => false], ['efi: certificate_base64 is not valid base64.']];
            }
            try {
                $pem = EfiSettings::readCertificate($contents, (string) ($efi['certificate_password'] ?? ''));
            } catch (EfiException $e) {
                return [['configured' => false], ['efi: '.$e->getMessage()]];
            }
        }
        $environment = EfiSettings::normalize($efi['environment'] ?? 'production');
        EfiSettings::store('environment', $environment);
        EfiSettings::storeCredential($environment, 'client_id', trim((string) $efi['client_id']));
        EfiSettings::storeCredential($environment, 'client_secret', trim((string) $efi['client_secret']));
        if (array_key_exists('pix_key', $efi)) {
            EfiSettings::storeCredential($environment, 'pix_key', filled($efi['pix_key']) ? trim((string) $efi['pix_key']) : null);
        }
        if (! is_null($pem)) {
            EfiSettings::storeCredential($environment, 'certificate', $pem[0]);
            EfiSettings::storeCredential($environment, 'private_key', $pem[1]);
        }
        EfiClient::forgetTokens();
        audit_log('platform.efi.settings_saved', null, ['environment' => $environment, 'certificate' => ! is_null($pem), 'source' => 'platform:configure'], 0, 'system');

        $status = ['configured' => true, 'pix' => EfiSettings::pixReady(), 'registered' => [], 'errors' => []];
        if (PlatformBilling::enabled() && ! PlatformBilling::isManual()) {
            try {
                $registration = PlatformBilling::registerWebhooks();
                $status['registered'] = $registration['registered'];
                $status['errors'] = $registration['errors'];
            } catch (\Throwable $e) {
                $status['errors'] = ['connection' => $e->getMessage()];
            }
        }

        return [$status, []];
    }

    /**
     * @return array{uuid: string, public_key: string}
     */
    private function nodeKey(): array
    {
        $key = PrivateKey::query()->where('team_id', 0)->where('name', self::NODE_KEY)->first();
        if (is_null($key)) {
            $pair = PrivateKey::generateNewKeyPair('ed25519');
            $key = PrivateKey::createAndStore([
                'name' => self::NODE_KEY,
                'description' => 'Key the dashboard uses to reach the nodes of the regions (created by platform:configure).',
                'private_key' => $pair['private_key'],
                'team_id' => 0,
            ]);
        }

        return ['uuid' => (string) $key->uuid, 'public_key' => trim((string) $key->getPublicKey())];
    }

    private function apiToken(string $name): ?string
    {
        $owner = User::query()->find(0);
        $team = Team::query()->find(0);
        if (is_null($owner) || is_null($team)) {
            return null;
        }
        $owner->tokens()->where('name', $name)->delete();
        // Coolify ties every token to the team of the session.
        session(['currentTeam' => $team]);
        $token = $owner->createToken($name, ['read', 'write']);
        audit_log('platform.api_token.created', $owner, ['name' => $name, 'source' => 'platform:configure'], 0, 'system');

        return $token->plainTextToken;
    }
}
