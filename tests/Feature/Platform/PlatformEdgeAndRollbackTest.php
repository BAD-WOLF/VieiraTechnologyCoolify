<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\InstanceSettings;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\EdgeRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.base_domain' => 'apps.example.com',
        'platform.edge.token' => 'edge-secret',
        'platform.edge.cache_seconds' => 1,
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://cloud.example.com']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

function edgeNode(PlatformRegion $region, string $ip): Server
{
    $server = Server::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => 'node-'.$ip,
        'ip' => $ip,
        'team_id' => 0,
        'private_key_id' => 1,
        'platform_region_id' => $region->id,
    ]);
    $server->settings->forceFill(['is_reachable' => true, 'is_usable' => true])->save();

    return $server;
}

function edgeWorld(Server $node, ?Team $team = null): array
{
    $team ??= Team::factory()->create();
    PlatformTenant::forTeam($team)->update(['plan' => 'pro', 'status' => PlatformTenant::STATUS_ACTIVE]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => "tenant-{$team->id}-{$node->id}",
        'network' => "tenant-{$team->id}",
        'server_id' => $node->id,
        'team_id' => $team->id,
    ]));
    $project = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Project '.$team->id, 'team_id' => $team->id]);

    return ['team' => $team, 'destination' => $destination, 'environment' => $project->environments()->first()];
}

function edgeApplication(array $world, string $fqdn): Application
{
    return Application::create([
        'name' => 'app-'.fake()->unique()->numberBetween(1, 99999),
        'git_repository' => 'https://github.com/coollabsio/coolify-examples',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'fqdn' => $fqdn,
        'environment_id' => $world['environment']->id,
        'destination_id' => $world['destination']->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

it('registers the platform routes ahead of the upstream catch-alls', function () {
    expect(Route::has('admin.platform'))->toBeTrue()
        ->and(Route::has('platform.onboarding'))->toBeTrue();

    $this->get('/api/v1/platform/metrics')->assertNotFound()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    $this->postJson('/api/v1/rollback')->assertUnauthorized();
    $this->get('/platform/onboarding')->assertRedirect(route('login'));
});

it('maps every public host of a region to the nodes running it', function () {
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true]);
    $other = PlatformRegion::create(['slug' => 'us-east-1', 'name' => 'Virginia']);
    $nodeA = edgeNode($region, '10.0.1.10');
    $nodeB = edgeNode($region, '10.0.1.11');
    $remote = edgeNode($other, '10.9.1.10');

    $world = edgeWorld($nodeA);
    edgeApplication($world, 'https://shop.apps.example.com,https://www.shop.com.br:8443');
    $replicated = edgeApplication($world, 'https://shop-api.apps.example.com');
    $replicated->additional_servers()->attach($nodeB->id, ['standalone_docker_id' => edgeWorld($nodeB, $world['team'])['destination']->id]);
    edgeApplication(edgeWorld($remote), 'https://elsewhere.apps.example.com');
    $legacy = edgeApplication($world, 'https://legacy.apps.example.com');
    Application::query()->whereKey($legacy->id)->update(['fqdn' => 'https://bad`host.example.com,http://localhost']);

    $config = EdgeRouter::configFor($region);
    $rules = collect($config['http']['routers'])->pluck('rule');

    expect($rules->all())->toContain('Host(`shop.apps.example.com`)', 'Host(`www.shop.com.br`)', 'Host(`shop-api.apps.example.com`)')
        ->and($rules->join(' '))->not->toContain('elsewhere')->not->toContain('bad')->not->toContain('localhost');

    $apiRouter = collect($config['tcp']['routers'])->firstWhere('rule', 'HostSNI(`shop-api.apps.example.com`)');
    expect($apiRouter['tls'])->toBe(['passthrough' => true])
        ->and($config['tcp']['services'][$apiRouter['service']]['loadBalancer']['servers'])->toBe([['address' => '10.0.1.10:443'], ['address' => '10.0.1.11:443']])
        ->and($config['http']['services'][$apiRouter['service']]['loadBalancer']['servers'])->toBe([['url' => 'http://10.0.1.10:80'], ['url' => 'http://10.0.1.11:80']]);

    $nodeB->settings->forceFill(['is_reachable' => false])->save();
    $config = EdgeRouter::configFor($region);
    $apiRouter = collect($config['tcp']['routers'])->firstWhere('rule', 'HostSNI(`shop-api.apps.example.com`)');
    expect($config['tcp']['services'][$apiRouter['service']]['loadBalancer']['servers'])->toBe([['address' => '10.0.1.10:443']]);
});

it('serves the edge configuration only with the edge token', function () {
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true]);
    edgeNode($region, '10.0.1.10');

    $this->get('/api/v1/platform/edge/sa-east-1')->assertNotFound();
    $this->get('/api/v1/platform/edge/sa-east-1', ['Authorization' => 'Bearer wrong'])->assertNotFound();
    $this->get('/api/v1/platform/edge/unknown', ['Authorization' => 'Bearer edge-secret'])->assertNotFound();

    $response = $this->get('/api/v1/platform/edge/sa-east-1', ['Authorization' => 'Bearer edge-secret']);
    $response->assertOk();
    expect($response->getContent())->toBe('{"http":{"routers":{},"services":{}},"tcp":{"routers":{},"services":{}}}');
});

it('rolls an application back to the previous successful commit', function () {
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true]);
    $node = edgeNode($region, '10.0.1.10');
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $world = edgeWorld($node, $team);
    $application = edgeApplication($world, 'https://shop.apps.example.com');
    ApplicationSetting::forceCreate(['application_id' => $application->id]);
    foreach (['aaa111', 'bbb222', 'bbb222'] as $commit) {
        ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => (string) new Cuid2,
            'server_id' => $node->id,
            'destination_id' => $world['destination']->id,
            'commit' => $commit,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
        ]);
    }
    session(['currentTeam' => $team]);
    $token = $user->createToken('ci', ['deploy'])->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token];

    $response = $this->postJson('/api/v1/rollback', ['uuid' => $application->uuid], $headers);
    $response->assertOk()->assertJsonPath('commit', 'aaa111');
    $queued = ApplicationDeploymentQueue::query()->where('deployment_uuid', $response->json('deployment_uuid'))->first();
    expect($queued->commit)->toBe('aaa111')->and((bool) $queued->rollback)->toBeTrue();

    $this->postJson('/api/v1/rollback', ['uuid' => $application->uuid, 'commit' => 'abc; rm -rf /'], $headers)->assertUnprocessable();
    $this->postJson('/api/v1/rollback', ['uuid' => 'missing'], $headers)->assertNotFound();

    $stranger = User::factory()->create();
    session(['currentTeam' => $stranger->teams()->first()]);
    $strangerToken = $stranger->createToken('ci', ['deploy'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/rollback', ['uuid' => $application->uuid], ['Authorization' => 'Bearer '.$strangerToken])->assertNotFound();
});
