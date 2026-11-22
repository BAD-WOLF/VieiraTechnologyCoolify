<?php

use App\Exceptions\PlatformPolicyViolation;
use App\Models\ApplicationSetting;
use App\Models\AuditLog;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PlatformSetting;
use App\Models\PlatformTenant;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Platform\PlatformConfig;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('ssh-keys');
    PlatformSetting::flushMemo();
    PlatformConfig::flush();
    PlatformConfig::$enabledInTests = true;
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.locale' => 'en',
        'platform.billing.provider' => 'manual',
        'platform.metrics_token' => 'metrics-token-0123456789',
        'platform.edge.token' => 'edge-token-0123456789abcd',
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.com.br']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

afterEach(function () {
    PlatformConfig::$enabledInTests = false;
    PlatformConfig::flush();
    PlatformSetting::flushMemo();
});

function configSaved(string $path, mixed $value): void
{
    DB::table('platform_settings')->updateOrInsert(['key' => PlatformConfig::PREFIX.$path], ['value' => Crypt::encryptString(json_encode($value)), 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * A customer of a plan with a project on a tenant destination, logged in.
 *
 * @return array{team: Team, environment: \App\Models\Environment, destination: StandaloneDocker, user: User}
 */
function configCustomer(string $plan = 'basic'): array
{
    auth()->forgetUser();
    $user = User::factory()->create();
    $team = $user->teams()->first();
    PlatformTenant::forTeam($team)->update(['plan' => $plan, 'status' => PlatformTenant::STATUS_ACTIVE]);
    $node = Server::query()->where('team_id', 0)->first() ?? Server::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'node-1', 'ip' => '10.20.8.10', 'team_id' => 0, 'private_key_id' => 1]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => "tenant-{$team->id}", 'network' => "tenant-{$team->id}", 'server_id' => $node->id, 'team_id' => $team->id,
    ]));
    $environment = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Loja', 'team_id' => $team->id])->environments()->first();
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return compact('team', 'environment', 'destination', 'user');
}

it('reads the saved settings again only after the given age, and ignores what it can not read', function () {
    configSaved('platform.support_email', 'suporte@hostrema.com.br');
    configSaved('platform.obsolete_setting', 'x');
    DB::table('platform_settings')->insert(['key' => PlatformConfig::PREFIX.'platform.sales_url', 'value' => 'not-encrypted', 'created_at' => now(), 'updated_at' => now()]);

    PlatformConfig::apply();
    expect(config('platform.support_email'))->toBe('suporte@hostrema.com.br')
        ->and(PlatformConfig::value('platform.support_email'))->toBe('suporte@hostrema.com.br')
        ->and(PlatformConfig::overrides())->toHaveKeys(['platform.support_email'])->not->toHaveKeys(['platform.obsolete_setting', 'platform.sales_url']);

    // A worker applies again at most every N seconds.
    configSaved('platform.support_email', 'novo@hostrema.com.br');
    PlatformConfig::apply(60);
    expect(config('platform.support_email'))->toBe('suporte@hostrema.com.br');
    PlatformConfig::apply();
    expect(config('platform.support_email'))->toBe('novo@hostrema.com.br');
});

it('applies the settings saved in the console before each queued job', function () {
    PlatformConfig::apply();
    configSaved('platform.support_email', 'fila@hostrema.com.br');
    PlatformConfig::flush();
    expect(config('platform.support_email'))->not->toBe('fila@hostrema.com.br');

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('payload')->andReturn([]);
    event(new \Illuminate\Queue\Events\JobProcessing('redis', $job));

    expect(config('platform.support_email'))->toBe('fila@hostrema.com.br');
});

it('keeps the configuration files when the settings table can not be read', function () {
    config(['platform.support_email' => 'arquivo@hostrema.com.br']);
    Schema::drop('platform_settings');

    PlatformConfig::apply();

    expect(config('platform.support_email'))->toBe('arquivo@hostrema.com.br');
});

it('reports a generated token it could not save, without stopping the start', function () {
    Exceptions::fake();
    config(['platform.metrics_token' => null]);
    PlatformSetting::saving(function (PlatformSetting $setting) {
        if ($setting->key === PlatformConfig::PREFIX.'platform.metrics_token') {
            throw new RuntimeException('somente leitura');
        }
    });

    PlatformConfig::apply();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'somente leitura');
    expect(config('platform.edge.token'))->toBe('edge-token-0123456789abcd');
});

it('normalizes and compares every kind of setting', function () {
    expect(fn () => PlatformConfig::normalize('platform.security.operator_api_cidrs', '203.0.113.0/24, 999.1.1.1/8'))
        ->toThrow(InvalidArgumentException::class, '"999.1.1.1/8" is not valid.')
        ->and(PlatformConfig::normalize('platform.security.operator_api_cidrs', '203.0.113.7 2001:db8::/32'))->toBe(['203.0.113.7', '2001:db8::/32'])
        ->and(fn () => PlatformConfig::normalize('platform.security.operator_api_cidrs', '10.0.0.0/33'))->toThrow(InvalidArgumentException::class)
        ->and(PlatformConfig::display('subscription.stripe_api_key'))->toBe('')
        ->and(PlatformConfig::rules('platform.logs.loki_url'))->toContain('regex:#^https?://[^\s]+$#')
        ->and(PlatformConfig::equals('platform.billing.due_days', '5', 5))->toBeTrue()
        ->and(PlatformConfig::equals('platform.nope', 1, 1))->toBeFalse();

    // A value equal to the default removes the saved one.
    $default = (int) config('platform.billing.due_days');
    PlatformConfig::set('platform.billing.due_days', $default + 2);
    expect(PlatformConfig::isOverridden('platform.billing.due_days'))->toBeTrue();
    PlatformConfig::set('platform.billing.due_days', (string) $default);
    expect(PlatformConfig::isOverridden('platform.billing.due_days'))->toBeFalse()
        ->and(DB::table('platform_settings')->where('key', PlatformConfig::PREFIX.'platform.billing.due_days')->exists())->toBeFalse()
        ->and(fn () => PlatformConfig::set('platform.nope', 1))->toThrow(InvalidArgumentException::class);
});

it('drops the compiled views when the branding changes, once per change', function () {
    $directory = storage_path('framework/views-test-'.uniqid());
    File::ensureDirectoryExists($directory);
    config(['view.compiled' => $directory]);
    $refresh = new ReflectionMethod(PlatformConfig::class, 'refreshBrandedViews');
    File::put($directory.'/compiled-a.php', '<?php echo "Coolify";');

    $refresh->invoke(null);
    expect(File::exists($directory.'/compiled-a.php'))->toBeFalse()
        ->and(File::exists($directory.'/.platform-branding'))->toBeTrue();

    File::put($directory.'/compiled-b.php', '<?php echo "Hostrema";');
    $refresh->invoke(null);
    expect(File::exists($directory.'/compiled-b.php'))->toBeTrue();

    config(['platform.name' => 'Outra Marca', 'platform.branding.enabled' => true]);
    $refresh->invoke(null);
    expect(File::exists($directory.'/compiled-b.php'))->toBeFalse();

    File::deleteDirectory($directory);
    config(['view.compiled' => $directory]);
    $refresh->invoke(null);
    expect(File::exists($directory))->toBeFalse();
});

it('keeps features out of plans without them, also when they are turned on later', function () {
    $customer = configCustomer('basic');
    $database = StandalonePostgresql::create([
        'name' => 'db', 'postgres_user' => 'app', 'postgres_password' => 'secret-password', 'postgres_db' => 'app',
        'environment_id' => $customer['environment']->id, 'destination_id' => $customer['destination']->id, 'destination_type' => StandaloneDocker::class,
    ]);

    expect(fn () => $database->update(['is_public' => true]))->toThrow(PlatformPolicyViolation::class, 'Not included in your plan')
        ->and(fn () => $database->update(['is_log_drain_enabled' => true]))->toThrow(PlatformPolicyViolation::class, 'Not included in your plan');
    $setting = new ApplicationSetting(['is_log_drain_enabled' => true]);
    $setting->application_id = 987654;
    expect(fn () => $setting->save())->not->toThrow(PlatformPolicyViolation::class);

    $pro = configCustomer('pro');
    $proDatabase = StandalonePostgresql::create([
        'name' => 'db', 'postgres_user' => 'app', 'postgres_password' => 'secret-password', 'postgres_db' => 'app',
        'environment_id' => $pro['environment']->id, 'destination_id' => $pro['destination']->id, 'destination_type' => StandaloneDocker::class,
    ]);
    $proDatabase->update(['is_log_drain_enabled' => true]);
    expect($proDatabase->fresh()->is_log_drain_enabled)->toBeTrue();

    // Off the platform nothing is checked.
    config(['platform.enabled' => false]);
    $database->update(['is_public' => true]);
    expect($database->fresh()->is_public)->toBeTrue();
});

it('refuses servers of customers, removes the destinations of a deleted team and audits what changes', function () {
    $customer = configCustomer('pro');
    expect(fn () => Server::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'meu-servidor', 'ip' => '203.0.113.50', 'team_id' => $customer['team']->id, 'private_key_id' => 1]))
        ->toThrow(PlatformPolicyViolation::class, 'Servers are managed by the platform.');

    $variable = EnvironmentVariable::create(['key' => 'APP_ENV', 'value' => 'production', 'resourceable_type' => \App\Models\Application::class, 'resourceable_id' => 987654]);
    $variable->delete();
    $token = $customer['user']->createToken('ci', ['read']);
    $token->accessToken->delete();
    TeamInvitation::create(['team_id' => $customer['team']->id, 'uuid' => (string) new Cuid2, 'email' => 'convidado@hostrema.com.br', 'role' => 'member', 'link' => 'https://painel.hostrema.com.br/invitations/x', 'via' => 'link']);
    event(new Failed('web', null, ['email' => 'intruso@hostrema.com.br', 'password' => 'x']));

    expect(AuditLog::query()->pluck('action')->all())->toContain('env.deleted', 'api_token.deleted', 'team.invitation.created', 'auth.failed')
        ->and(AuditLog::query()->where('action', 'auth.failed')->first())->actor_type->toBe('anonymous')
        ->and(AuditLog::query()->where('action', 'auth.failed')->first()->metadata['email'])->toBe('intruso@hostrema.com.br')
        ->and(collect(AuditLog::query()->where('action', 'auth.failed')->first()->metadata))->not->toHaveKey('password');

    $destination = $customer['destination'];
    auth()->forgetUser();
    $customer['team']->delete();
    expect(StandaloneDocker::query()->whereKey($destination->id)->exists())->toBeFalse();
});

it('stops the configuration with a clear error for each wrong input', function (array $options, string $error, ?string $stdin = null) {
    $file = null;
    if (! is_null($stdin)) {
        $file = tempnam(sys_get_temp_dir(), 'configure');
        file_put_contents($file, $stdin);
        $options['--file'] = $file;
    }

    $this->artisan('platform:configure', $options)->expectsOutputToContain($error)->assertFailed();

    if ($file) {
        unlink($file);
    }
})->with([
    'unreadable file' => [['--file' => '/nao/existe.json'], 'Can not read /nao/existe.json.'],
    'not JSON' => [[], 'The configuration is not a JSON object.', 'isto não é json'],
    'pair without a value' => [['--set' => ['platform.base_domain']], '--set needs path=value (platform.base_domain).'],
    'list item that is not valid' => [['--set' => ['platform.security.operator_api_cidrs=999.1.1.1/8']], 'platform.security.operator_api_cidrs: "999.1.1.1/8" is not valid.'],
    'address without a scheme' => [['--set' => ['platform.logs.loki_url=loki:3100']], 'platform.logs.loki_url:'],
    'token without an owner' => [['--api-token' => 'ansible'], 'There is no owner yet: run platform:install first.'],
    'e-mail without a host' => [[], 'smtp: The host field is required.', '{"smtp": {"port": 587, "from_address": "nao-responda@hostrema.com.br"}}'],
    'Efí without credentials' => [[], 'efi: The client id field is required.', '{"efi": {"environment": "sandbox"}}'],
    'certificate that is not base64' => [[], 'efi: certificate_base64 is not valid base64.', '{"efi": {"client_id": "a", "client_secret": "b", "certificate_base64": "%%%"}}'],
    'certificate that is not one' => [[], 'efi: The .p12 certificate could not be read.', '{"efi": {"client_id": "a", "client_secret": "b", "certificate_base64": "'.base64_encode('isto não é um certificado').'"}}'],
]);

it('refuses to configure while the platform is off', function () {
    config(['platform.enabled' => false]);

    $this->artisan('platform:configure', ['--set' => ['platform.base_domain=apps.hostrema.com.br']])
        ->expectsOutputToContain('The platform is off.')
        ->assertFailed();
});

it('reports an Efí that does not answer while configuring, and keeps the credentials', function () {
    config(['platform.billing.provider' => 'efi']);
    Http::fake(fn () => throw new RuntimeException('rede indisponível'));
    $file = tempnam(sys_get_temp_dir(), 'configure');
    file_put_contents($file, json_encode(['efi' => ['environment' => 'sandbox', 'client_id' => 'Client_Id_x', 'client_secret' => 'Client_Secret_x']]));

    expect(\Illuminate\Support\Facades\Artisan::call('platform:configure', ['--file' => $file, '--json' => true]))->toBe(0);
    $output = json_decode(trim(\Illuminate\Support\Facades\Artisan::output()), true);
    unlink($file);

    expect($output['efi'])->configured->toBeTrue()->errors->toBe(['connection' => 'rede indisponível'])
        ->and(\App\Services\Platform\Efi\EfiSettings::credential('sandbox', 'client_secret'))->toBe('Client_Secret_x');
});

it('installs on an empty database: the owner gets the root team', function () {
    DB::table('teams')->where('id', 0)->delete();

    $this->artisan('platform:install', ['--email' => 'Dono@Hostrema.com.br', '--password' => 'owner-pass-1234', '--url' => 'https://painel.hostrema.com.br', '--no-interaction' => true])
        ->assertSuccessful();

    $owner = User::query()->find(0);
    expect($owner->email)->toBe('dono@hostrema.com.br')
        ->and(Team::query()->find(0))->not->toBeNull()
        ->and($owner->teams()->whereKey(0)->exists())->toBeTrue();
});

it('asks the e-mail of the owner when it was not given', function () {
    $this->artisan('platform:install', ['--password' => 'owner-pass-1234', '--url' => 'https://painel.hostrema.com.br'])
        ->expectsQuestion('E-mail of the owner', 'dona@hostrema.com.br')
        ->assertSuccessful();

    expect(User::query()->find(0)->email)->toBe('dona@hostrema.com.br');
});

it('refuses to install on a database that is not migrated', function () {
    Schema::drop('platform_settings');

    $this->artisan('platform:install', ['--email' => 'dono@hostrema.com.br'])
        ->expectsOutputToContain('The database is not migrated yet.')
        ->assertFailed();
});

it('keeps a demo customer that already exists and says when it can not be provisioned yet', function () {
    $this->artisan('platform:install', ['--local' => true, '--skip-node' => true, '--password' => 'owner-1234', '--demo' => true, '--demo-password' => 'demo-1234'])
        ->assertSuccessful();
    // No region left to provision in: the tenant lost its own and none is active.
    PlatformTenant::query()->update(['platform_region_id' => null]);
    \App\Models\PlatformRegion::query()->update(['is_active' => false, 'is_default' => false]);

    $this->artisan('platform:install', ['--local' => true, '--skip-node' => true, '--demo' => true])
        ->expectsOutputToContain('(already existed)')
        ->expectsOutputToContain('The demo customer was not provisioned yet:')
        ->assertSuccessful();
});

it('re-encrypts with the current key and counts what no key opens', function () {
    DB::table('platform_settings')->insert(['key' => 'quebrado', 'value' => 'eyJpdiI6ImludmFsaWRvIn0=', 'created_at' => now(), 'updated_at' => now()]);
    PlatformSetting::put('legivel', 'valor');
    config(['app.previous_keys' => []]);

    $this->artisan('platform:rotate-app-key')
        ->expectsOutputToContain('APP_PREVIOUS_KEYS is empty')
        ->expectsOutputToContain('value(s) could not be decrypted with APP_KEY nor APP_PREVIOUS_KEYS and were left as they are.')
        ->assertFailed();

    PlatformSetting::flushMemo();
    expect(PlatformSetting::get('legivel'))->toBe('valor')
        ->and(DB::table('platform_settings')->where('key', 'quebrado')->value('value'))->toBe('eyJpdiI6ImludmFsaWRvIn0=');
});
