<?php

use App\Livewire\Platform\Logs;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\TenantLogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.logs.loki_url' => null,
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://cloud.example.com']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

/**
 * @return array{user: User, team: Team, application: Application, region: PlatformRegion}
 */
function logsWorld(?PlatformRegion $region = null): array
{
    $region ??= PlatformRegion::query()->firstOrCreate(['slug' => 'sa-east-1'], ['name' => 'São Paulo', 'is_default' => true, 'logs_url' => 'http://10.20.0.10:3100']);
    $node = Server::query()->where('team_id', 0)->first() ?? Server::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => 'node-1', 'ip' => '10.20.8.10', 'team_id' => 0, 'private_key_id' => 1, 'platform_region_id' => $region->id,
    ]);
    $user = User::factory()->create();
    $team = $user->teams()->first();
    PlatformTenant::forTeam($team)->update(['plan' => 'pro', 'status' => PlatformTenant::STATUS_ACTIVE, 'platform_region_id' => $region->id]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => "tenant-{$team->id}-{$node->id}", 'network' => "tenant-{$team->id}",
        'server_id' => $node->id, 'team_id' => $team->id,
    ]));
    $project = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Project '.$team->id, 'team_id' => $team->id]);
    $application = Application::create([
        'name' => 'shop', 'git_repository' => 'https://github.com/coollabsio/coolify-examples', 'git_branch' => 'main',
        'build_pack' => 'nixpacks', 'ports_exposes' => '3000', 'environment_id' => $project->environments()->first()->id,
        'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class,
    ]);

    return ['user' => $user, 'team' => $team, 'application' => $application, 'region' => $region];
}

function fakeLoki(): void
{
    Http::fake([
        'http://10.20.0.10:3100/loki/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
            ['stream' => ['container' => 'shop-1'], 'values' => [['1763215200000000000', "second line\n"], ['1763215100000000000', 'first line']]],
            ['stream' => ['container' => 'shop-2'], 'values' => [['1763215300000000000', 'third line']]],
        ]]]),
    ]);
}

it('pins every log query to the tenant and the resource and keeps the filter literal', function () {
    expect(TenantLogs::logQuery(42, 'abc123'))->toBe('{tenant_id="42", container=~".*abc123.*"}')
        ->and(TenantLogs::logQuery(42, 'abc123', 'error "x" | json'))->toBe('{tenant_id="42", container=~".*abc123.*"} |= `error "x" | json`');

    expect(fn () => TenantLogs::logQuery(42, 'abc123', 'a` or {tenant_id="7"} |= `'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TenantLogs::logQuery(42, 'abc123', "line\nbreak"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TenantLogs::logQuery(42, 'abc".*"}', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TenantLogs::logQuery(0, 'abc123', ''))->toThrow(InvalidArgumentException::class);
});

it('reads the log history of the tenant resource from the regional Loki', function () {
    $world = logsWorld();
    fakeLoki();

    $result = TenantLogs::fetch($world['team']->id, $world['application'], '6h', 'line');

    expect($result['error'])->toBeNull()
        ->and(array_column($result['lines'], 'line'))->toBe(['first line', 'second line', 'third line']);
    Http::assertSent(function (HttpRequest $request) use ($world) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['query'] === '{tenant_id="'.$world['team']->id.'", container=~".*'.$world['application']->uuid.'.*"} |= `line`'
            && $query['direction'] === 'backward';
    });

    expect(TenantLogs::fetch($world['team']->id, $world['application'], '2y')['error'])->not->toBeNull();

    $world['region']->update(['logs_url' => null]);
    expect(TenantLogs::fetch($world['team']->id, $world['application'])['error'])->toBe('The log history of this region is not configured yet.');
});

it('serves the log history through the API only for resources of the token team', function () {
    $world = logsWorld();
    $other = logsWorld($world['region']);
    fakeLoki();
    session(['currentTeam' => $world['team']]);
    $headers = ['Authorization' => 'Bearer '.$world['user']->createToken('ci', ['read'])->plainTextToken];

    $this->getJson('/api/v1/platform/logs?resource_uuid='.$world['application']->uuid.'&range=1h', $headers)
        ->assertOk()->assertJsonCount(3, 'lines')->assertJsonPath('lines.0.line', 'first line');
    $this->getJson('/api/v1/platform/logs?resource_uuid='.$other['application']->uuid, $headers)->assertNotFound();
    $this->getJson('/api/v1/platform/logs?resource_uuid='.$world['application']->uuid.'&range=1y', $headers)->assertUnprocessable();
});

it('shows the log history page with the team resources only', function () {
    $world = logsWorld();
    $other = logsWorld($world['region']);
    fakeLoki();
    $this->actingAs($world['user']);
    session(['currentTeam' => $world['team']]);

    Livewire::test(Logs::class)
        ->assertSee('shop (Application)')
        ->set('resourceUuid', $world['application']->uuid)
        ->set('range', '24h')
        ->call('search')
        ->assertSet('error', null)
        ->assertSee('third line');

    Livewire::test(Logs::class)
        ->set('resourceUuid', $other['application']->uuid)
        ->call('search')
        ->assertSet('error', 'Resource not found.')
        ->assertDontSee('first line');
});

it('stores the Loki address of the region when the operator registers a node', function () {
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo']);
    $node = Server::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'node-1', 'ip' => '10.20.8.10', 'team_id' => 0, 'private_key_id' => 1]);
    $operator = User::factory()->create();
    $root = Team::find(0);
    $root->members()->attach($operator->id, ['role' => 'owner']);
    session(['currentTeam' => $root]);
    $token = $operator->createToken('ansible', ['read', 'write'])->plainTextToken;

    $this->postJson("/api/v1/platform/servers/{$node->uuid}/region", ['region' => 'sa-east-1', 'logs_url' => 'http://10.20.0.10:3100/'], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect($region->fresh()->logs_url)->toBe('http://10.20.0.10:3100');
});

it('makes the proxy of a node trust the edge routers of its region', function () {
    \Illuminate\Support\Facades\Queue::fake();
    PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo']);
    $node = Server::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'node-1', 'ip' => '10.20.8.10', 'team_id' => 0, 'private_key_id' => 1]);
    $operator = User::factory()->create();
    $root = Team::find(0);
    $root->members()->attach($operator->id, ['role' => 'owner']);
    session(['currentTeam' => $root]);
    $token = $operator->createToken('ansible', ['read', 'write'])->plainTextToken;
    $url = "/api/v1/platform/servers/{$node->uuid}/region";

    $this->postJson($url, ['region' => 'sa-east-1', 'trusted_proxies' => ['10.20.0.0/16; rm -rf /']], ['Authorization' => 'Bearer '.$token])
        ->assertUnprocessable()->assertJsonValidationErrors(['trusted_proxies.0']);
    $this->postJson($url, ['region' => 'sa-east-1', 'trusted_proxies' => ['10.20.0.0/16']], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    \Illuminate\Support\Facades\Queue::assertPushed(fn (\Lorisleiva\Actions\Decorators\JobDecorator $job) => $job->getAction() instanceof \App\Actions\Platform\TrustEdgeProxies
        && $job->getParameters()[1] === ['10.20.0.0/16']);
});

it('adds the networks of the edge to the entrypoints of Traefik once', function () {
    $configuration = \Symfony\Component\Yaml\Yaml::dump(['name' => 'coolify-proxy', 'services' => ['traefik' => [
        'image' => 'traefik:v3.6',
        'command' => ['--ping=true', '--entrypoints.http.address=:80', '--entrypoints.https.address=:443', '--entrypoints.http.forwardedheaders.trustedips=10.99.0.0/24'],
    ]]], 12, 2);

    $updated = \App\Actions\Platform\TrustEdgeProxies::withTrustedProxies($configuration, ['10.20.0.0/16', '10.20.0.0/16']);
    $command = \Symfony\Component\Yaml\Yaml::parse($updated)['services']['traefik']['command'];

    expect($command)->toBe([
        '--ping=true', '--entrypoints.http.address=:80', '--entrypoints.https.address=:443',
        '--entrypoints.http.forwardedheaders.trustedips=10.20.0.0/16',
        '--entrypoints.https.proxyprotocol.trustedips=10.20.0.0/16',
    ])
        ->and(\App\Actions\Platform\TrustEdgeProxies::withTrustedProxies($updated, ['10.20.0.0/16']))->toBeNull()
        ->and(\App\Actions\Platform\TrustEdgeProxies::withTrustedProxies('services: {caddy: {image: caddy}}', ['10.20.0.0/16']))->toBeNull()
        ->and(\App\Actions\Platform\TrustEdgeProxies::isCidr('10.20.0.0/16'))->toBeTrue()
        ->and(\App\Actions\Platform\TrustEdgeProxies::isCidr('fd00::/8'))->toBeTrue()
        ->and(\App\Actions\Platform\TrustEdgeProxies::isCidr('10.20.0.0/33'))->toBeFalse()
        ->and(\App\Actions\Platform\TrustEdgeProxies::isCidr('10.20.0.0'))->toBeFalse();
});
