<?php

use App\Livewire\Platform\Observability;
use App\Models\InstanceSettings;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Team;
use App\Models\User;
use App\Providers\PlatformServiceProvider;
use App\Services\Platform\HardeningContext;
use App\Services\Platform\TenantContainerPolicy;
use App\Services\Platform\TenantLimits;
use App\Services\Platform\TenantTelemetry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.telemetry.endpoint' => 'https://otlp.{region}.hostrema.com.br',
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0]);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

function isolationTenant(string $plan = 'pro'): PlatformTenant
{
    $user = User::factory()->create();
    $team = $user->teams()->first();
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    $region = PlatformRegion::query()->firstOrCreate(['slug' => 'sa-east-1'], ['name' => 'São Paulo', 'is_default' => true]);
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(['plan' => $plan, 'status' => PlatformTenant::STATUS_ACTIVE, 'platform_region_id' => $region->id]);

    return $tenant->fresh();
}

function isolationContext(int $teamId): HardeningContext
{
    return new HardeningContext(
        teamId: $teamId, plan: 'pro', resourceUuid: 'abc123', resourceKind: 'application',
        baseDirectory: '/data/coolify/applications/abc123', bindRoots: ['/data/coolify/applications/abc123'],
        allowedNetworks: ["tenant-{$teamId}"], allowedHosts: ['app.example.com'],
        maxCpus: 2, maxMemoryMb: 4096, defaultCpus: 0.5, defaultMemoryMb: 512, pidsLimit: 512, resourceIds: [],
    );
}

it('puts every tenant container in the tenant slice and keeps the policy idempotent', function () {
    $compose = ['services' => ['web' => ['image' => 'nginx:alpine']]];
    $first = (new TenantContainerPolicy(isolationContext(42)))->apply($compose)['compose'];
    expect($first['services']['web']['cgroup_parent'])->toBe('tenant-42.slice');

    $second = (new TenantContainerPolicy(isolationContext(42)))->apply($first)['compose'];
    expect($second['services']['web']['cgroup_parent'])->toBe('tenant-42.slice');

    $foreign = ['services' => ['web' => ['image' => 'nginx:alpine', 'cgroup_parent' => 'tenant-7.slice']]];
    expect(fn () => (new TenantContainerPolicy(isolationContext(42)))->apply($foreign))->toThrow(\App\Exceptions\PlatformPolicyViolation::class);
});

it('turns the plan into node limits for the whole tenant', function () {
    $tenant = isolationTenant('pro');
    $limits = TenantLimits::of($tenant);
    expect($limits)->toMatchArray(['cpus' => 2.0, 'memory_mb' => 4096, 'read_mbps' => 200, 'write_mbps' => 200, 'iops' => 4000, 'bandwidth_mbps' => 200])
        ->and($limits['tasks'])->toBe(512 * 30);

    $command = TenantLimits::command($tenant);
    expect($command)->toStartWith('if command -v hostrema-tenant-limits')
        ->toContain("'apply' '{$tenant->team_id}' '--network=tenant-{$tenant->team_id}' '--cpus=2' '--memory-mb=4096'")
        ->toContain("'--bandwidth-mbps=200'");

    $tenant->update(['quota_overrides' => ['cpus' => 3.5, 'bandwidth_mbps' => 0]]);
    expect(TenantLimits::command($tenant->fresh()))->toContain("'--cpus=3.5'")->toContain("'--bandwidth-mbps=0'");
});

it('authorizes tenant traces with a per-tenant token', function () {
    $tenant = isolationTenant('pro');
    $token = TenantTelemetry::issueToken($tenant);

    $this->get('/api/v1/platform/otlp/auth')->assertUnauthorized();
    $this->get('/api/v1/platform/otlp/auth', ['Authorization' => 'Bearer hrt_wrong'])->assertUnauthorized();
    $this->get('/api/v1/platform/otlp/auth', ['Authorization' => 'Bearer '.$token, 'X-Scope-OrgID' => 'tenant-1'])
        ->assertOk()->assertHeader('X-Scope-OrgID', 'tenant-'.$tenant->team_id);
    $this->get('/api/v1/platform/otlp/auth', ['Authorization' => 'Basic '.base64_encode('otlp:'.$token)])
        ->assertOk()->assertHeader('X-Scope-OrgID', 'tenant-'.$tenant->team_id);

    $tenant->update(['plan' => 'basic']);
    $this->get('/api/v1/platform/otlp/auth', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();

    $tenant->update(['plan' => 'pro']);
    TenantTelemetry::revoke($tenant->fresh());
    $this->get('/api/v1/platform/otlp/auth', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
});

it('shows the OTLP endpoint and the token once', function () {
    isolationTenant('pro');

    Livewire::test(Observability::class)
        ->assertSee('https://otlp.sa-east-1.hostrema.com.br')
        ->call('generate')
        ->assertSee('hrt_');

    isolationTenant('basic');
    Livewire::test(Observability::class)->assertSee('Available on the Profissional and Enterprise plans.');
});

it('runs every scheduled task on one instance in cluster mode', function () {
    $schedule = new Schedule;
    $schedule->command('inspire')->everyMinute();
    $schedule->call(fn () => null)->everyMinute();

    PlatformServiceProvider::runScheduleOnOneServer($schedule);

    foreach ($schedule->events() as $event) {
        expect($event->onOneServer)->toBeTrue()->and($event->description)->not->toBeEmpty();
    }
});
