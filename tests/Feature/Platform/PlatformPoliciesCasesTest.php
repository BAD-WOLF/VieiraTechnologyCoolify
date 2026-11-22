<?php

use App\Exceptions\PlatformPolicyViolation;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\AuditLog;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMongodb;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\DestinationPolicy;
use App\Services\Platform\DomainPolicy;
use App\Services\Platform\HardeningContext;
use App\Services\Platform\PathPolicy;
use App\Services\Platform\TenantContainerPolicy;
use App\Services\Platform\TenantInputGuard;
use App\Services\Platform\WebhookUrlGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.base_domain' => 'hostrema.app',
        'platform.reserved_hosts' => [],
        'platform.container.enforce_storage_opt' => false,
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.app']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

/**
 * A tenant with an application (domains), a service with a web container and a PostgreSQL database.
 *
 * @return array{team: Team, node: Server, destination: StandaloneDocker, application: Application, service: Service, web: ServiceApplication, database: StandalonePostgresql}
 */
function policiesWorld(string $fqdn = 'https://loja.cliente.com.br,https://loja.hostrema.app'): array
{
    $region = PlatformRegion::query()->firstOrCreate(['slug' => 'sa-east-1'], ['name' => 'São Paulo', 'is_default' => true]);
    $node = Server::query()->where('team_id', 0)->first() ?? Server::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => 'node-1', 'ip' => '10.20.8.10', 'team_id' => 0, 'private_key_id' => 1, 'platform_region_id' => $region->id,
    ]);
    auth()->forgetUser();
    $team = User::factory()->create()->teams()->first();
    PlatformTenant::forTeam($team)->update(['plan' => 'pro', 'status' => PlatformTenant::STATUS_ACTIVE, 'platform_region_id' => $region->id]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => "tenant-{$team->id}-{$node->id}", 'network' => "tenant-{$team->id}",
        'server_id' => $node->id, 'team_id' => $team->id,
    ]));
    $environment = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Loja', 'team_id' => $team->id])->environments()->first();
    $application = Application::create([
        'name' => 'shop', 'git_repository' => 'https://github.com/coollabsio/coolify-examples', 'git_branch' => 'main',
        'build_pack' => 'nixpacks', 'ports_exposes' => '3000', 'environment_id' => $environment->id, 'fqdn' => $fqdn,
        'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class,
    ]);
    $service = Service::create([
        'name' => 'uptime', 'docker_compose_raw' => "services:\n  web:\n    image: 'nginx:alpine'\n",
        'environment_id' => $environment->id, 'server_id' => $node->id,
        'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class,
    ]);
    $web = ServiceApplication::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'web', 'image' => 'nginx:alpine', 'service_id' => $service->id]);
    $database = StandalonePostgresql::create([
        'name' => 'db', 'postgres_user' => 'app', 'postgres_password' => 'secret-password', 'postgres_db' => 'app',
        'environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class,
    ]);

    return compact('team', 'node', 'destination', 'application', 'service', 'web', 'database');
}

it('reads hosts only from valid domain lists and compose domains', function () {
    expect(DomainPolicy::hostsFromComposeDomains('isto não é json'))->toBe([])
        ->and(DomainPolicy::hostsFromComposeDomains('"texto"'))->toBe([])
        ->and(DomainPolicy::hostsFromComposeDomains(['web' => ['domain' => 'https://a.cliente.com,https://b.cliente.com']]))->toBe(['a.cliente.com', 'b.cliente.com'])
        ->and(DomainPolicy::hostOf(''))->toBeNull()
        ->and(DomainPolicy::hostOf('http://'))->toBeNull()
        ->and(DomainPolicy::hostOf('Loja.Cliente.com.'))->toBe('loja.cliente.com');
});

it('reserves the panel, the base domain and the configured names', function () {
    config(['platform.reserved_hosts' => ['', ' status.hostrema.com ', 'admin', 'API']]);

    expect(DomainPolicy::reservedHosts())->toBe(['painel.hostrema.app', 'hostrema.app', 'status.hostrema.com', 'admin.hostrema.app', 'api.hostrema.app']);

    config(['platform.base_domain' => null]);
    expect(DomainPolicy::reservedHosts())->toBe(['painel.hostrema.app', 'status.hostrema.com'])
        // Without a base domain every host is a customer domain.
        ->and(DomainPolicy::isCustomHost('loja.hostrema.app'))->toBeTrue();
});

it('refuses invalid, reserved and foreign domains, including the ones of previews', function () {
    $owner = policiesWorld();
    $preview = ApplicationPreview::forceCreate(['uuid' => (string) new Cuid2, 'pull_request_id' => 7, 'pull_request_html_url' => 'https://github.com/acme/loja/pull/7',
        'fqdn' => 'https://pr-7.loja.cliente.com.br', 'application_id' => $owner['application']->id]);
    $other = policiesWorld('https://outra.cliente.com.br');

    expect(DomainPolicy::hostsOwnedBy($owner['team']))->toContain('pr-7.loja.cliente.com.br')
        ->and(DomainPolicy::ownerOf('pr-7.loja.cliente.com.br'))->toBe($owner['team']->id)
        ->and(DomainPolicy::customHostCount($owner['team']->id, ['nova.cliente.com.br', 'loja.cliente.com.br']))->toBe(3);

    try {
        DomainPolicy::assertAvailable(['dominio_invalido!', 'painel.hostrema.app', 'pr-7.loja.cliente.com.br', 'livre.cliente.com.br'], $other['team']->id);
        $this->fail('The domains were accepted.');
    } catch (PlatformPolicyViolation $violation) {
        expect($violation->violations)->toBe([
            '"dominio_invalido!" is not a valid domain.',
            'The domain "painel.hostrema.app" is reserved by the platform.',
            'The domain "pr-7.loja.cliente.com.br" is already used by another account.',
        ]);
    }
    DomainPolicy::assertAvailable(['pr-7.loja.cliente.com.br'], $owner['team']->id);

    // A preview whose application is gone belongs to nobody.
    Application::withoutEvents(fn () => $owner['application']->forceDelete());
    expect(DomainPolicy::ownerOf('pr-7.loja.cliente.com.br'))->toBeNull()
        ->and($preview->fresh())->not->toBeNull();
});

it('lets a team use only its own destinations, and the operator every one', function () {
    $world = policiesWorld();
    $other = policiesWorld('https://outra.cliente.com.br');

    expect(DestinationPolicy::isUsableBy(null, $world['team']))->toBeFalse()
        ->and(DestinationPolicy::isUsableBy($world['destination'], null))->toBeFalse()
        ->and(DestinationPolicy::isUsableBy($world['destination'], 0))->toBeTrue()
        ->and(DestinationPolicy::isUsableBy($world['destination'], $world['team']))->toBeTrue()
        ->and(DestinationPolicy::isUsableBy($world['destination'], $other['team']->id))->toBeFalse()
        ->and(DestinationPolicy::destinationsOn($world['node'], 0)->pluck('id')->all())->toEqualCanonicalizing($world['node']->destinations()->pluck('id')->all())
        ->and(DestinationPolicy::destinationsOn($world['node'], $world['team'])->pluck('id')->all())->toBe([$world['destination']->id]);

    $stolen = new Application(['destination_type' => StandaloneDocker::class, 'destination_id' => $other['destination']->id, 'environment_id' => $world['application']->environment_id]);
    expect(fn () => DestinationPolicy::assertResourceDestination($stolen))->toThrow(PlatformPolicyViolation::class, 'does not belong to your team');
    // Nothing to check: no destination, an unknown destination type, or a self-hosted instance.
    DestinationPolicy::assertResourceDestination(new Application(['environment_id' => $world['application']->environment_id]));
    DestinationPolicy::assertResourceDestination(new Application(['destination_type' => 'App\\Models\\Nothing', 'destination_id' => 1, 'environment_id' => $world['application']->environment_id]));
    config(['constants.coolify.self_hosted' => true]);
    DestinationPolicy::assertResourceDestination($stolen);
});

it('confines every kind of resource to its own directory on the node', function () {
    $world = policiesWorld();

    expect(PathPolicy::rootsFor(null))->toBe([])
        ->and(PathPolicy::rootsFor(new Application))->toBe([])
        ->and(PathPolicy::rootsFor($world['database']))->toBe([database_configuration_dir().'/'.$world['database']->uuid])
        ->and(PathPolicy::rootsFor($world['web']))->toBe([service_configuration_dir().'/'.$world['service']->uuid])
        ->and(PathPolicy::rootsFor(new ServiceApplication(['service_id' => 987654])))->toBe([])
        ->and(PathPolicy::isWithin(null, ['/data']))->toBeFalse()
        ->and(PathPolicy::normalize(''))->toBeNull()
        ->and(PathPolicy::isSafeInnerPath('', false))->toBeFalse()
        ->and(PathPolicy::isSafeInnerPath(null))->toBeTrue();

    // The development environment keeps the data in a docker volume.
    config(['app.env' => 'local']);
    expect(PathPolicy::rootsFor($world['application']))->toBe([
        application_configuration_dir().'/'.$world['application']->uuid,
        '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/applications/'.$world['application']->uuid,
    ]);
});

it('builds the hardening context of a database and of a preview', function () {
    $world = policiesWorld();
    $network = "tenant-{$world['team']->id}";

    $database = HardeningContext::for($world['database'], $world['team']);
    expect($database)->resourceKind->toBe('database')->resourceIds->toBe(['coolify.databaseid' => $world['database']->id])
        ->baseDirectory->toBe(database_configuration_dir().'/'.$world['database']->uuid)
        ->dedicatedNode->toBeFalse()
        ->and($database->allowedNetworks)->toContain($network)->toContain($world['database']->uuid);

    $preview = HardeningContext::for($world['application'], $world['team'], 7, 0);
    expect($preview->allowedNetworks)->toContain($world['application']->uuid.'-7')->toContain("{$network}-7")
        ->and($preview->dedicatedNode)->toBeFalse()
        ->and($preview->allowedHosts)->toContain('loja.cliente.com.br');
});

it('limits the writable layer of the containers to the storage of the plan when the nodes support it', function () {
    $world = policiesWorld();
    $context = HardeningContext::for($world['application'], $world['team']);
    $compose = ['services' => ['web' => ['image' => 'nginx', 'storage_opt' => ['size' => '500G']]]];

    expect((new TenantContainerPolicy($context))->apply($compose)['compose']['services']['web'])->not->toHaveKey('storage_opt');

    config(['platform.container.enforce_storage_opt' => true, 'platform.plans.pro.quotas.storage_gb' => 50]);
    expect((new TenantContainerPolicy($context))->apply($compose)['compose']['services']['web']['storage_opt'])->toBe(['size' => '50G']);
});

it('accepts only safe database names, init scripts, aliases, mount paths and volume names', function () {
    $world = policiesWorld();
    $rejects = function (Model $model, string $field): string {
        try {
            TenantInputGuard::validate($model);
        } catch (ValidationException $e) {
            return (string) ($e->errors()[$field][0] ?? '');
        }

        return '';
    };
    $backup = fn (string $type, string $databases) => (new ScheduledDatabaseBackup)->forceFill(['database_type' => $type, 'databases_to_backup' => $databases]);

    expect($rejects($backup(StandalonePostgresql::class, 'app,analytics'), 'databases_to_backup'))->toBe('')
        ->and($rejects($backup(StandalonePostgresql::class, 'app; DROP DATABASE app'), 'databases_to_backup'))->toContain('Database names may only contain')
        ->and($rejects($backup(StandaloneMongodb::class, 'app:users,orders|logs'), 'databases_to_backup'))->toBe('')
        ->and($rejects($backup(StandaloneMongodb::class, 'app$(id)'), 'databases_to_backup'))->toContain('Database names may only contain')
        ->and($rejects((new StandalonePostgresql)->forceFill(['init_scripts' => [['filename' => '01-schema.sql', 'content' => 'select 1;']]]), 'init_scripts'))->toBe('')
        ->and($rejects((new StandalonePostgresql)->forceFill(['init_scripts' => [['filename' => '../../etc/cron.d/x', 'content' => '*']]]), 'init_scripts'))->toContain('Init script file names')
        ->and($rejects((new Application)->forceFill(['custom_network_aliases' => 'web,api.interna']), 'custom_network_aliases'))->toBe('')
        ->and($rejects((new Application)->forceFill(['custom_network_aliases' => '-x']), 'custom_network_aliases'))->toBe('Invalid network alias.')
        ->and($rejects((new Application)->forceFill(['custom_docker_run_options' => '   ']), 'custom_docker_run_options'))->toBe('');

    $volume = ['resource_type' => $world['database']->getMorphClass(), 'resource_id' => $world['database']->id];
    expect($rejects((new LocalFileVolume)->forceFill($volume + ['mount_path' => 'relative/path']), 'mount_path'))->toBe('Invalid mount path.')
        ->and($rejects((new LocalFileVolume)->forceFill($volume + ['mount_path' => '/etc/app.conf']), 'mount_path'))->toBe('')
        ->and($rejects((new LocalPersistentVolume)->forceFill($volume + ['name' => 'dados com espaço']), 'name'))->toBe('Invalid volume name.')
        ->and($rejects((new LocalPersistentVolume)->forceFill($volume + ['name' => 'outro-volume']), 'name'))->toBe('Volume names must contain the resource UUID.')
        ->and($rejects((new LocalPersistentVolume)->forceFill($volume + ['name' => $world['database']->uuid.'-data']), 'name'))->toBe('');
    $serviceVolume = ['resource_type' => $world['web']->getMorphClass(), 'resource_id' => $world['web']->id];
    expect($rejects((new LocalPersistentVolume)->forceFill($serviceVolume + ['name' => $world['service']->uuid.'_web-data']), 'name'))->toBe('');
});

it('refuses webhook URLs without a usable port or address', function () {
    WebhookUrlGuard::resolveUsing(null);

    expect(WebhookUrlGuard::validate('https://hooks.example.com:0/x'))->toBe('Invalid webhook URL.')
        ->and(WebhookUrlGuard::validate('https://hostrema-teste.invalid/x'))->toBe('The webhook host could not be resolved.')
        ->and(WebhookUrlGuard::isPublicIp('não-é-ip'))->toBeFalse()
        ->and(WebhookUrlGuard::isPublicIp('93.184.216.34'))->toBeTrue();
});

it('finds the team of every kind of model, and of none', function () {
    $world = policiesWorld();
    $team = $world['team']->id;
    $preview = ApplicationPreview::forceCreate(['uuid' => (string) new Cuid2, 'pull_request_id' => 3, 'pull_request_html_url' => 'https://github.com/acme/loja/pull/3', 'application_id' => $world['application']->id]);
    $owned = new class extends Model
    {
        public function team(): ?Team
        {
            return Team::find(0);
        }
    };

    expect(platformTeamOf(null))->toBeNull()
        ->and(platformTeamOf($world['team'])->id)->toBe($team)
        ->and(platformTeamOf(ServiceApplication::query()->with('service')->find($world['web']->id))->id)->toBe($team)
        ->and(platformTeamOf((new ScheduledTask)->forceFill(['application_id' => $world['application']->id]))->id)->toBe($team)
        ->and(platformTeamOf((new ScheduledTask)->forceFill(['service_id' => $world['service']->id]))->id)->toBe($team)
        ->and(platformTeamOf((new EnvironmentVariable)->forceFill(['resourceable_type' => ApplicationPreview::class, 'resourceable_id' => $preview->id]))->id)->toBe($team)
        ->and(platformTeamOf((new EnvironmentVariable)->forceFill(['resourceable_type' => 'App\\Models\\Nothing', 'resourceable_id' => 1])))->toBeNull()
        ->and(platformTeamOf($owned)->id)->toBe(0)
        ->and(platformTeamOf(new class extends Model {}))->toBeNull();

    auth()->forgetUser();
    expect(isPlatformTenant())->toBeFalse()
        ->and(isPlatformTenant($team))->toBeTrue()
        ->and(isPlatformTenant(0))->toBeFalse();
});

it('never lets a failing audit entry break the audited action, even inside a transaction', function () {
    Exceptions::fake();
    $team = policiesWorld()['team'];

    \Illuminate\Support\Facades\DB::transaction(function () use ($team) {
        audit_log(str_repeat('ação-longa-', 40), null, [], 0, 'system');
        // The transaction of the audited action goes on.
        $team->update(['name' => 'Loja renomeada']);
        audit_log('team.renamed', null, [], $team->id, 'system');
    });

    Exceptions::assertReported(QueryException::class);
    expect($team->fresh()->name)->toBe('Loja renomeada')
        ->and(AuditLog::query()->where('action', 'team.renamed')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'like', 'ação-longa-%')->count())->toBe(0);

    // A second failure leaves the connection usable as well.
    audit_log(str_repeat('ação-longa-', 40), null, [], 0, 'system');
    expect(AuditLog::query()->where('action', 'team.renamed')->count())->toBe(1);
});
