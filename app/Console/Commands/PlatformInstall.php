<?php

namespace App\Console\Commands;

use App\Actions\Platform\ProvisionTenant;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\ValidateServer;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\PlatformRegion;
use App\Models\PlatformSetting;
use App\Models\PlatformTenant;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\PlatformConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Leaves a new installation ready to sell: owner, sign up, API, first region and the defaults of
 * the settings panel. With --local it also builds the local test environment of infra/local
 * (local node over SSH, Traefik, manual payments, Mailpit and a demo customer).
 *
 * Safe to run again: it only creates what is missing and never overwrites a value the owner
 * changed in the settings panel.
 */
class PlatformInstall extends Command
{
    public const LOCAL_NODE_KEY = 'Hostrema local node';

    public const PROFILE_SETTING = 'install.profile';

    /** @var array<string, mixed> */
    public const PRODUCTION_DEFAULTS = [
        'platform.container.runtime' => 'runsc',
    ];

    /** @var array<string, mixed> */
    public const LOCAL_DEFAULTS = [
        'platform.billing.provider' => 'manual',
        'platform.billing.allow_simulated_payments' => true,
        'platform.billing.manual_instructions' => 'Ambiente local de testes: clique em "Simular pagamento" para liberar o plano.',
        'platform.base_domain' => 'localhost',
        'platform.container.runtime' => null,
        'platform.container.dedicated_runtime' => null,
        'platform.container.tenant_slices' => false,
        'platform.container.enforce_storage_opt' => false,
        'platform.security.require_two_factor' => 'none',
        'platform.webhooks.allow_http' => true,
        'platform.webhooks.operator_private_targets' => true,
        'platform.telemetry.endpoint' => null,
        'platform.logs.loki_url' => null,
    ];

    protected $signature = 'platform:install
        {--email= : E-mail of the owner (only when there is no owner yet)}
        {--password= : Password of the owner (a random one is generated when empty)}
        {--name=Owner : Name of the owner}
        {--reset-password : Also set --password on an owner that already exists}
        {--url= : Public URL of the dashboard (e.g. https://painel.example.com)}
        {--region= : Slug of the first region (default sa-east-1, or "local" with --local)}
        {--region-name= : Name of the first region}
        {--without-gvisor : Do not run the customers in gVisor (only when the nodes do not have it)}
        {--local : Local test environment (infra/local): local node, manual payments, Mailpit}
        {--node-host=hostrema-node : SSH host of the local node}
        {--node-port=22 : SSH port of the local node}
        {--node-public-key : Only print the public SSH key of the local node (created when missing)}
        {--skip-node : Do not register the local node}
        {--smtp-host=mailpit : SMTP host of the local environment}
        {--smtp-port=1025 : SMTP port of the local environment}
        {--demo : Create a demo customer with an active Pro plan}
        {--demo-email=demo@hostrema.local : E-mail of the demo customer}
        {--demo-password= : Password of the demo customer (random when empty)}';

    protected $description = 'Prepare the platform: owner, sign up, region and defaults (with --local, the local test environment)';

    /** @var list<array{0: string, 1: string}> */
    private array $summary = [];

    public function handle(): int
    {
        if (! isPlatform()) {
            $this->error('The platform is off. Set PLATFORM_ENABLED=true in the environment and run again.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('platform_settings') || ! Schema::hasTable('users')) {
            $this->error('The database is not migrated yet. Run "php artisan migrate --force" first.');

            return self::FAILURE;
        }
        $local = (bool) $this->option('local');

        if ($this->option('node-public-key')) {
            $this->line(trim((string) $this->localNodeKey()->getPublicKey()));

            return self::SUCCESS;
        }

        PlatformConfig::apply();
        $owner = $this->ensureOwner($local);
        if (is_null($owner)) {
            return self::FAILURE;
        }
        $this->configureInstance($local);
        $region = $this->ensureRegion($local);
        $profile = $local ? 'local' : ($this->option('without-gvisor') ? 'production-runc' : 'production');
        $this->applyDefaults($profile, match ($profile) {
            'local' => self::LOCAL_DEFAULTS,
            'production' => self::PRODUCTION_DEFAULTS,
            default => [],
        });
        PlatformConfig::apply();

        if ($local && ! $this->option('skip-node')) {
            $this->ensureLocalNode($region);
        }
        if ($this->option('demo')) {
            $this->ensureDemoCustomer();
        }

        $this->newLine();
        $this->info(config('platform.name').' is ready.');
        $this->table(['', ''], $this->summary);
        $this->line($local
            ? 'Next: open the dashboard, sign up as a new customer (or use the demo account) and deploy an application to http://<name>.localhost.'
            : 'Next: Efí Bank credentials in /admin/platform (Billing), transactional e-mail in Settings > Transactional e-mail and the nodes with Ansible (docs/region-onboarding.md).');

        return self::SUCCESS;
    }

    private function ensureOwner(bool $local): ?User
    {
        $owner = User::query()->find(0);
        $password = (string) $this->option('password');
        if ($owner) {
            if ($this->option('reset-password') && $password !== '') {
                $owner->forceFill(['password' => Hash::make($password)])->save();
                $this->summary[] = ['Owner', "{$owner->email} (new password)"];
            } else {
                $this->summary[] = ['Owner', "{$owner->email} (already existed)"];
            }
        } else {
            $email = (string) ($this->option('email') ?: ($local ? 'owner@hostrema.local' : ''));
            if ($email === '' && $this->input->isInteractive()) {
                $email = (string) $this->ask('E-mail of the owner');
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Give the e-mail of the owner with --email.');

                return null;
            }
            $generated = $password === '';
            $password = $generated ? Str::password(20, symbols: false) : $password;
            $attributes = ['id' => 0, 'name' => (string) $this->option('name'), 'email' => strtolower($email), 'password' => Hash::make($password)];
            if (Team::query()->whereKey(0)->exists()) {
                $owner = User::withoutEvents(fn () => User::query()->forceCreate($attributes));
                Team::query()->find(0)->members()->syncWithoutDetaching([$owner->id => ['role' => 'owner']]);
            } else {
                $owner = User::query()->forceCreate($attributes);
            }
            $this->summary[] = ['Owner', $owner->email];
            $this->summary[] = ['Owner password', $generated ? $password : '(the one you gave)'];
        }
        if (is_null($owner->email_verified_at)) {
            $owner->forceFill(['email_verified_at' => now()])->save();
        }
        // The setup is done here: the Coolify boarding (add a server...) is not for the operator.
        Team::query()->whereKey(0)->update(['show_boarding' => false]);

        return $owner;
    }

    private function configureInstance(bool $local): void
    {
        $settings = InstanceSettings::query()->firstOrCreate(['id' => 0]);
        // Coolify keeps only scheme and host of the instance domain, so the local dashboard (port
        // 8000) relies on APP_URL instead.
        $url = rtrim((string) $this->option('url'), '/');
        $changes = [
            // A paid platform lives from sign ups; Coolify closes them after the first user.
            'is_registration_enabled' => true,
            // Customers and Ansible use the API.
            'is_api_enabled' => true,
        ];
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $changes['fqdn'] = $url;
        }
        if ($local) {
            $changes += [
                'is_dns_validation_enabled' => false,
                'is_auto_update_enabled' => false,
            ];
            if (! $settings->smtp_enabled && ! $settings->resend_enabled) {
                $changes += [
                    'smtp_enabled' => true,
                    'smtp_host' => (string) $this->option('smtp-host'),
                    'smtp_port' => (int) $this->option('smtp-port'),
                    'smtp_encryption' => 'none',
                    'smtp_username' => null,
                    'smtp_password' => null,
                    'smtp_from_address' => 'nao-responda@hostrema.local',
                    'smtp_from_name' => (string) config('platform.name'),
                ];
            }
        }
        $settings->forceFill($changes)->save();
        $this->summary[] = ['Dashboard', $settings->fqdn ?: (string) config('app.url')];
        $this->summary[] = ['Sign up', 'open'];
        if ($local) {
            $this->summary[] = ['E-mails', 'Mailpit (http://localhost:8025)'];
        }
    }

    private function ensureRegion(bool $local): PlatformRegion
    {
        $slug = Str::slug((string) ($this->option('region') ?: ($local ? 'local' : config('platform.default_region', 'sa-east-1'))));
        $region = PlatformRegion::query()->where('slug', $slug)->first();
        if (is_null($region)) {
            $region = PlatformRegion::query()->create([
                'slug' => $slug,
                'name' => (string) ($this->option('region-name') ?: ($local ? 'Local (Docker)' : Str::upper($slug))),
                'is_active' => true,
                'is_default' => ! PlatformRegion::query()->where('is_default', true)->exists(),
            ]);
        }
        $this->summary[] = ['Region', "{$region->name} ({$region->slug})"];

        return $region;
    }

    /**
     * Defaults of the profile, once: a later run must not undo what the owner chose in the panel,
     * not even a choice equal to the value of the configuration files.
     *
     * @param  array<string, mixed>  $defaults
     */
    private function applyDefaults(string $profile, array $defaults): void
    {
        if (PlatformSetting::get(self::PROFILE_SETTING) !== $profile) {
            foreach ($defaults as $path => $value) {
                if (! PlatformConfig::isOverridden($path)) {
                    PlatformConfig::set($path, $value);
                }
            }
            PlatformSetting::put(self::PROFILE_SETTING, $profile);
        }
        $this->summary[] = ['Payments', match (true) {
            PlatformBilling::isManual() => 'manual'.(config('platform.billing.allow_simulated_payments') ? ' (customers can simulate the payment)' : ''),
            PlatformBilling::enabled() => 'Efí Bank',
            default => (string) config('platform.billing.provider'),
        }];
        $this->summary[] = ['Container runtime', filled(config('platform.container.runtime')) ? (string) config('platform.container.runtime') : 'runc (Docker default)'];
    }

    private function localNodeKey(): PrivateKey
    {
        $key = PrivateKey::query()->where('team_id', 0)->where('name', self::LOCAL_NODE_KEY)->first();
        if ($key) {
            return $key;
        }
        $pair = PrivateKey::generateNewKeyPair('ed25519');

        return PrivateKey::createAndStore([
            'name' => self::LOCAL_NODE_KEY,
            'description' => 'Key of the local node (infra/local).',
            'private_key' => $pair['private_key'],
            'team_id' => 0,
        ]);
    }

    private function ensureLocalNode(PlatformRegion $region): void
    {
        $host = (string) $this->option('node-host');
        $key = $this->localNodeKey();
        $server = Server::query()->where('team_id', 0)->where('ip', $host)->first();
        if (is_null($server)) {
            $server = Server::query()->create([
                'name' => 'local-node',
                'description' => 'Local node (Docker-in-Docker) of infra/local.',
                'ip' => $host,
                'port' => (int) $this->option('node-port'),
                'user' => 'root',
                'team_id' => 0,
                'private_key_id' => $key->id,
            ]);
            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();
        }
        $server->forceFill(['platform_region_id' => $region->id, 'platform_dedicated_team_id' => null])->save();
        $server->settings->forceFill(['wildcard_domain' => 'http://localhost', 'is_build_server' => false])->save();

        $this->line("Connecting to the local node {$host}...");
        try {
            ValidateServer::run($server->fresh());
            $server->refresh();
            $server->settings->refresh();
            if (! $server->settings->is_usable) {
                throw new \RuntimeException('Docker on the node was not accepted. See the validation logs of the server.');
            }
            $server->validateCoolifyNetwork(isSwarm: false, isBuildServer: false);
            $this->line('Starting the proxy (Traefik) on the node; the first time it downloads the image...');
            StartProxy::run($server, async: false, force: true);
            $this->summary[] = ['Local node', "{$host} (ready)"];
        } catch (\Throwable $e) {
            $this->warn('The local node is not ready: '.strip_tags(Str::limit($e->getMessage(), 300)));
            $this->warn('Check that the node container is running and run this command again.');
            $this->summary[] = ['Local node', "{$host} (not validated)"];

            return;
        }
        PlatformTenant::query()->whereNotNull('provisioned_at')->where('platform_region_id', $region->id)->pluck('team_id')
            ->each(fn ($teamId) => ProvisionTenant::run((int) $teamId, $region));
        $this->summary[] = ['Applications', 'http://<name>.localhost'];
    }

    private function ensureDemoCustomer(): void
    {
        $email = strtolower((string) $this->option('demo-email'));
        $user = User::query()->where('email', $email)->first();
        $password = (string) $this->option('demo-password');
        if (is_null($user)) {
            $generated = $password === '';
            $password = $generated ? Str::password(16, symbols: false) : $password;
            $user = User::query()->create(['name' => 'Cliente Demo', 'email' => $email, 'password' => Hash::make($password)]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->summary[] = ['Demo customer', $email];
            $this->summary[] = ['Demo password', $generated ? $password : '(the one you gave)'];
        } else {
            $this->summary[] = ['Demo customer', "{$email} (already existed)"];
        }
        $team = $user->teams()->where('personal_team', true)->first() ?? $user->teams()->first();
        if (is_null($team) || (int) $team->id === 0) {
            $this->warn('The demo customer has no team of its own.');

            return;
        }
        $tenant = PlatformTenant::forTeam($team);
        if (! PlatformBilling::hasActivePlan($tenant)) {
            $tenant->update([
                'plan' => 'pro',
                'billing_interval' => PlanCatalog::INTERVAL_MONTHLY,
                'status' => PlatformTenant::STATUS_ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
                'billing_provider' => PlatformBilling::PROVIDER,
                'suspension_reason' => null,
                'suspended_at' => null,
            ]);
            audit_log('platform.tenant.demo_created', $tenant, ['plan' => 'pro'], $team->id, 'system');
        }
        PlatformBilling::syncCoolifySubscription($tenant->fresh());
        try {
            ProvisionTenant::run($team);
        } catch (\Throwable $e) {
            $this->warn('The demo customer was not provisioned yet: '.$e->getMessage());
        }
    }
}
