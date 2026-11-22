<?php

use App\Events\PlatformEventOccurred;
use App\Exceptions\PlatformPolicyViolation;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PlatformAllocation;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\PlatformEvents;
use App\Services\Platform\PlatformGate;
use App\Services\Platform\TenantLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config([
        'constants.coolify.self_hosted' => false,
        'constants.ssh.mux_enabled' => false,
        'platform.enabled' => true,
        'platform.events.stream' => '',
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.app']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

/**
 * A tenant with an application, a service with a web component and a PostgreSQL database on a reachable node.
 *
 * @return array{team: Team, tenant: PlatformTenant, node: Server, destination: StandaloneDocker, environment: \App\Models\Environment, application: Application}
 */
function gateWorld(): array
{
    $region = PlatformRegion::query()->firstOrCreate(['slug' => 'sa-east-1'], ['name' => 'São Paulo', 'is_default' => true]);
    $key = PrivateKey::query()->firstOrCreate(['name' => 'gate-node', 'team_id' => 0], ['private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key']]);
    $node = Server::query()->where('team_id', 0)->where('name', 'gate-node')->first() ?? Server::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => 'gate-node', 'ip' => '10.20.8.20', 'team_id' => 0, 'private_key_id' => $key->id, 'platform_region_id' => $region->id,
    ]);
    $node->settings->forceFill(['is_reachable' => true, 'is_usable' => true])->save();
    auth()->forgetUser();
    $team = User::factory()->create()->teams()->first();
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(['plan' => 'pro', 'status' => PlatformTenant::STATUS_ACTIVE, 'platform_region_id' => $region->id]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => "tenant-{$team->id}-{$node->id}", 'network' => "tenant-{$team->id}",
        'server_id' => $node->id, 'team_id' => $team->id,
    ]));
    $environment = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Loja', 'team_id' => $team->id])->environments()->first();
    $application = Application::create([
        'name' => 'shop', 'git_repository' => 'https://github.com/coollabsio/coolify-examples', 'git_branch' => 'main',
        'build_pack' => 'nixpacks', 'ports_exposes' => '3000', 'environment_id' => $environment->id,
        'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class,
    ]);

    return ['team' => $team, 'tenant' => $tenant->fresh(), 'node' => $node->fresh(), 'destination' => $destination, 'environment' => $environment, 'application' => $application];
}

function gateDeployments(Application $application, int $count, ?\Carbon\CarbonInterface $at = null): void
{
    foreach (range(1, $count) as $i) {
        $deployment = ApplicationDeploymentQueue::create([
            'application_id' => $application->id, 'deployment_uuid' => (string) new Cuid2, 'pull_request_id' => 0,
            'commit' => 'HEAD', 'status' => 'finished', 'server_id' => $application->destination->server_id,
        ]);
        if ($at) {
            ApplicationDeploymentQueue::query()->whereKey($deployment->id)->update(['created_at' => $at]);
        }
    }
}

it('counts the deployments of the last 24 hours against the plan', function () {
    config(['platform.plans.pro.quotas.deployments_per_day' => 3]);
    $world = gateWorld();
    $other = gateWorld();
    gateDeployments($world['application'], 2);
    gateDeployments($world['application'], 5, now()->subDay()->subMinute());
    gateDeployments($other['application'], 5);

    // The deployment being started is already queued: the third one of the day still runs.
    gateDeployments($world['application'], 1);
    PlatformGate::assertCanDeploy($world['application']->fresh());

    gateDeployments($world['application'], 1);
    expect(fn () => PlatformGate::assertCanDeploy($world['application']->fresh()))->toThrow(PlatformPolicyViolation::class, 'Your plan allows 3 deployments per day.');

    config(['platform.plans.pro.quotas.deployments_per_day' => 0]);
    PlatformGate::assertCanDeploy($world['application']->fresh());
});

it('refuses deployments of a blocked tenant and ignores what is not a tenant workload', function () {
    $world = gateWorld();
    $world['tenant']->update(['status' => PlatformTenant::STATUS_SUSPENDED, 'suspension_reason' => TenantLifecycle::REASON_OVERDUE, 'suspended_at' => now()]);

    expect(fn () => PlatformGate::assertCanDeploy($world['application']->fresh()))->toThrow(PlatformPolicyViolation::class)
        ->and(PlatformGate::applies(null))->toBeFalse()
        ->and(PlatformGate::applies(new Application))->toBeFalse();
    PlatformGate::assertCanDeploy(new Application);

    config(['platform.enabled' => false]);
    expect(PlatformGate::applies($world['application']))->toBeFalse();
    PlatformGate::assertCanDeploy($world['application']->fresh());
});

it('refuses databases and compose stacks to a plan without them', function () {
    $world = gateWorld();
    config(['platform.plans.pro.features.databases' => false, 'platform.plans.pro.features.docker_compose' => false]);

    expect(fn () => StandalonePostgresql::create([
        'name' => 'db', 'postgres_user' => 'app', 'postgres_password' => 'secret-password', 'postgres_db' => 'app',
        'environment_id' => $world['environment']->id, 'destination_id' => $world['destination']->id, 'destination_type' => StandaloneDocker::class,
    ]))->toThrow(PlatformPolicyViolation::class, '"Databases" is not part of your plan')
        ->and(fn () => Service::create([
            'name' => 'uptime', 'docker_compose_raw' => "services:\n  web:\n    image: 'nginx:alpine'\n",
            'environment_id' => $world['environment']->id, 'server_id' => $world['node']->id,
            'destination_id' => $world['destination']->id, 'destination_type' => StandaloneDocker::class,
        ]))->toThrow(PlatformPolicyViolation::class)
        ->and(fn () => Application::create([
            'name' => 'stack', 'git_repository' => 'https://github.com/coollabsio/coolify-examples', 'git_branch' => 'main',
            'build_pack' => 'dockercompose', 'ports_exposes' => '3000', 'environment_id' => $world['environment']->id,
            'destination_id' => $world['destination']->id, 'destination_type' => StandaloneDocker::class,
        ]))->toThrow(PlatformPolicyViolation::class);
    expect(StandalonePostgresql::query()->count())->toBe(0)
        ->and(Service::query()->count())->toBe(0)
        ->and(Application::query()->where('name', 'stack')->exists())->toBeFalse();
});

it('creates the network of the tenant on the node before a deployment, and only for tenant destinations', function () {
    Process::fake();
    $world = gateWorld();
    $network = "tenant-{$world['team']->id}";

    PlatformGate::ensureNetwork($world['application']);

    Process::assertRan(fn ($process) => str_contains((string) $process->command, "docker network create --driver bridge --attachable --label coolify.managed=true --label coolify.tenantId={$world['team']->id} '{$network}'")
        && str_contains((string) $process->command, "docker network connect '{$network}' coolify-proxy"));

    // Nothing more for an operator destination, a resource that is not a tenant workload or a node that is down.
    $operatorDestination = StandaloneDocker::query()->where('server_id', $world['node']->id)->whereNull('team_id')->firstOrFail();
    PlatformGate::ensureNetwork($world['application'], $world['node'], $operatorDestination);
    PlatformGate::ensureNetwork(new Application);
    $world['node']->settings->forceFill(['is_reachable' => false])->save();
    PlatformGate::ensureNetwork($world['application']->fresh());
    Process::assertRanTimes(fn ($process) => str_contains((string) $process->command, 'docker network'), 1);
});

it('keeps the containers of a tenant within the plan when hardening a workload', function () {
    config(['platform.plans.pro.quotas.containers' => 1]);
    $world = gateWorld();
    $compose = ['services' => ['web' => ['image' => 'nginx'], 'worker' => ['image' => 'busybox']]];

    expect(fn () => PlatformGate::harden($world['application'], $compose))->toThrow(PlatformPolicyViolation::class, 'Container quota exceeded (2 of 1).')
        ->and(PlatformAllocation::query()->count())->toBe(0)
        // Not a tenant workload: the compose file is left as it is.
        ->and(PlatformGate::harden(new Application, $compose))->toBe($compose);
});

it('releases the reservations of one server, one preview or every one of a workload', function () {
    $world = gateWorld();
    $reserve = fn (int $server, int $preview) => PlatformAllocation::query()->create([
        'team_id' => $world['team']->id, 'resource_type' => $world['application']->getMorphClass(), 'resource_id' => $world['application']->id,
        'server_id' => $server, 'pull_request_id' => $preview, 'cpus' => 0.5, 'memory_mb' => 512, 'containers' => 1,
    ]);
    $reserve(1, 0);
    $reserve(1, 5);
    $reserve(2, 0);
    $reserve(2, 5);
    $left = fn () => PlatformAllocation::query()->orderBy('server_id')->orderBy('pull_request_id')->get()->map(fn ($row) => "{$row->server_id}:{$row->pull_request_id}")->all();

    config(['platform.enabled' => false]);
    PlatformGate::release($world['application']);
    expect($left())->toBe(['1:0', '1:5', '2:0', '2:5']);

    config(['platform.enabled' => true]);
    PlatformGate::release($world['application'], 1, 5);
    expect($left())->toBe(['1:0', '2:0', '2:5']);
    PlatformGate::release($world['application'], pullRequestId: 5);
    expect($left())->toBe(['1:0', '2:0']);
    PlatformGate::release($world['application'], 2);
    expect($left())->toBe(['1:0']);
    PlatformGate::release($world['application']);
    expect($left())->toBe([]);
});

it('tells the tenant about the status of service components and the result of backups', function () {
    Event::fake([PlatformEventOccurred::class]);
    $world = gateWorld();
    $service = Service::create([
        'name' => 'uptime', 'docker_compose_raw' => "services:\n  web:\n    image: 'nginx:alpine'\n",
        'environment_id' => $world['environment']->id, 'server_id' => $world['node']->id,
        'destination_id' => $world['destination']->id, 'destination_type' => StandaloneDocker::class,
    ]);
    $web = ServiceApplication::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'web', 'image' => 'nginx:alpine', 'service_id' => $service->id, 'status' => 'exited']);
    $database = StandalonePostgresql::create([
        'name' => 'db', 'postgres_user' => 'app', 'postgres_password' => 'secret-password', 'postgres_db' => 'app',
        'environment_id' => $world['environment']->id, 'destination_id' => $world['destination']->id, 'destination_type' => StandaloneDocker::class,
    ]);
    $schedule = ScheduledDatabaseBackup::forceCreate([
        'uuid' => (string) new Cuid2, 'enabled' => true, 'save_s3' => false, 'frequency' => '0 3 * * *',
        'database_type' => $database->getMorphClass(), 'database_id' => $database->id, 'team_id' => $world['team']->id,
    ]);
    $of = fn (string $type) => Event::dispatched(PlatformEventOccurred::class, fn (PlatformEventOccurred $event) => $event->type === $type)->map(fn ($call) => $call[0])->values();

    $web->update(['status' => 'running:healthy']);
    $running = ScheduledDatabaseBackupExecution::create(['uuid' => (string) new Cuid2, 'status' => 'running', 'scheduled_database_backup_id' => $schedule->id, 'database_name' => 'app']);
    $running->update(['status' => 'success', 'size' => '2048']);
    ScheduledDatabaseBackupExecution::create(['uuid' => (string) new Cuid2, 'status' => 'failed', 'scheduled_database_backup_id' => $schedule->id, 'database_name' => 'app', 'message' => str_repeat('x', 900)]);
    ScheduledDatabaseBackupExecution::create(['uuid' => (string) new Cuid2, 'status' => 'success', 'scheduled_database_backup_id' => 987654]);

    $status = $of('resource.status_changed')->firstWhere(fn ($event) => ($event->data['component'] ?? null) === 'web');
    expect($status)->not->toBeNull()
        ->and($status->teamId)->toBe($world['team']->id)
        ->and($status->data)->toMatchArray(['status' => 'running:healthy', 'previous_status' => 'exited'])
        ->and($status->data['resource'])->toBe(['uuid' => $service->uuid, 'name' => 'uptime', 'type' => 'service']);
    expect($of('backup.succeeded'))->toHaveCount(1)
        ->and($of('backup.succeeded')->first()->data)->toMatchArray(['backup_uuid' => $schedule->uuid, 'database_name' => 'app', 'size_bytes' => 2048, 'message' => null])
        ->and($of('backup.succeeded')->first()->data['database'])->toBe(['uuid' => $database->uuid, 'name' => 'db', 'type' => 'postgresql'])
        ->and($of('backup.failed'))->toHaveCount(1)
        ->and(mb_strlen($of('backup.failed')->first()->data['message']))->toBe(503);
});

it('keeps recording events when the event stream is down, and none while muted or off the platform', function () {
    Event::fake([PlatformEventOccurred::class]);
    Exceptions::fake();
    config(['platform.events.stream' => 'hostrema-test:events', 'database.redis.default.port' => 1, 'database.redis.default.read_timeout' => 1]);
    Redis::purge();

    $event = PlatformEvents::dispatch('tenant.custom', 7, ['ok' => true]);

    expect($event)->not->toBeNull()->and($event->type)->toBe('tenant.custom');
    Event::assertDispatched(PlatformEventOccurred::class, 1);
    Exceptions::assertReported(fn (RedisException $e) => true);

    expect(PlatformEvents::muted(fn () => PlatformEvents::dispatch('tenant.custom', 7)))->toBeNull()
        ->and(PlatformEvents::isMuted())->toBeFalse();
    config(['platform.enabled' => false]);
    expect(PlatformEvents::dispatch('tenant.custom', 7))->toBeNull();
    Event::assertDispatched(PlatformEventOccurred::class, 1);
});
