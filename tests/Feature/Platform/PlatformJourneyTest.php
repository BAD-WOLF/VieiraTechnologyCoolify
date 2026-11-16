<?php

use App\Exceptions\PlatformPolicyViolation;
use App\Livewire\Project\New\DockerImage;
use App\Livewire\Project\New\PublicGitRepository;
use App\Livewire\Project\New\SimpleDockerfile;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\DomainPolicy;
use App\Services\Platform\InterBilling;
use App\Services\Platform\PlatformGate;
use App\Services\Platform\ServiceTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

/*
 * The journeys of a customer through the real screens (Livewire components and pages) with every
 * platform guard on: what a unit test of one guard cannot show is a guard rejecting a value that
 * Coolify itself writes.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    journeyRedirector(app()->getBindings()['redirect']);
    Queue::fake();
    Storage::fake('ssh-keys');
    Process::fake();
    $this->withoutVite();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.locale' => 'en',
        'platform.base_domain' => 'apps.example.com',
        'platform.container.public_port_min' => 20000,
        'platform.container.public_port_max' => 29999,
        'subscription.stripe_api_key' => null,
        // Aligned at boot when PLATFORM_ENABLED is set (see PlatformServiceProvider).
        'subscription.provider' => 'stripe',
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://cloud.example.com']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
    if (! GithubApp::query()->whereKey(0)->exists()) {
        GithubApp::forceCreate([
            'id' => 0, 'uuid' => (string) new Cuid2, 'name' => 'Public GitHub', 'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com', 'is_public' => true, 'team_id' => 0,
        ]);
    }
});

/**
 * A customer on an active Professional plan, signed in, with a project and its destination on a
 * working node of the default region.
 *
 * @return array{user: User, team: Team, tenant: PlatformTenant, node: Server, destination: StandaloneDocker, project: Project, environment: Environment}
 */
function journeyWorld(): array
{
    $region = PlatformRegion::query()->firstOrCreate(['slug' => 'sa-east-1'], ['name' => 'São Paulo', 'is_default' => true, 'is_active' => true]);
    $key = PrivateKey::query()->firstOrCreate(['name' => 'journey-node', 'team_id' => 0], [
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    // The server the platform itself runs on, as in production.
    Server::query()->whereKey(0)->exists() || Server::forceCreate([
        'id' => 0, 'uuid' => (string) new Cuid2, 'name' => 'localhost', 'ip' => 'host.docker.internal', 'team_id' => 0, 'private_key_id' => $key->id,
    ]);
    $node = Server::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => 'node-'.fake()->unique()->numberBetween(1, 9999),
        'ip' => '10.20.0.'.fake()->unique()->numberBetween(2, 250),
        'team_id' => 0,
        'private_key_id' => $key->id,
        'platform_region_id' => $region->id,
    ]);
    $node->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'wildcard_domain' => 'https://apps.example.com'])->save();

    $user = User::factory()->create(['name' => 'Maria']);
    $team = $user->teams()->first();
    $team->forceFill(['show_boarding' => false])->save();
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(['plan' => 'pro', 'status' => PlatformTenant::STATUS_ACTIVE, 'current_period_end' => now()->addMonth(), 'provisioned_at' => now()]);
    InterBilling::syncCoolifySubscription($tenant->fresh());
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => "tenant-{$team->id}",
        'network' => "tenant-{$team->id}",
        'server_id' => $node->id,
        'team_id' => $team->id,
    ]));
    $project = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Loja', 'team_id' => $team->id]);

    test()->actingAs($user);
    session(['currentTeam' => $team]);
    Cache::forget('team:'.$user->id);

    return [
        'user' => $user, 'team' => $team, 'tenant' => $tenant->fresh(), 'node' => $node, 'destination' => $destination,
        'project' => $project, 'environment' => $project->environments()->first(),
    ];
}

/**
 * @param  array<string, mixed>  $world
 */
function journeyNewResource(string $component, array $world): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test($component)
        ->set('parameters', ['project_uuid' => $world['project']->uuid, 'environment_uuid' => $world['environment']->uuid])
        ->set('query', ['destination' => $world['destination']->uuid, 'server_id' => $world['node']->id]);
}

function journeyGithubApi(): void
{
    Http::fake(['https://api.github.com/*' => Http::response(['name' => 'main'], 200, [
        'X-RateLimit-Remaining' => '59',
        'X-RateLimit-Reset' => (string) now()->addHour()->timestamp,
    ])]);
}

it('creates an application from a public GitHub repository', function () {
    $world = journeyWorld();
    journeyGithubApi();

    journeyNewResource(PublicGitRepository::class, $world)
        ->set('repository_url', 'https://github.com/BAD-WOLF/StreamVibe.git')
        ->call('loadBranch')
        ->assertNotDispatched('error')
        ->assertSet('branchFound', true)
        ->call('submit')
        ->assertNotDispatched('error')
        ->assertRedirect();

    $application = Application::query()->latest('id')->first();
    expect($application->git_repository)->toBe('BAD-WOLF/StreamVibe')
        ->and($application->source_type)->toBe(GithubApp::class)
        ->and($application->fqdn)->toEndWith('.apps.example.com');
});

it('creates an application from a repository of another Git host', function () {
    $world = journeyWorld();

    journeyNewResource(PublicGitRepository::class, $world)
        ->set('repository_url', 'https://gitlab.com/gitlab-org/gitlab-test.git')
        ->call('loadBranch')
        ->assertNotDispatched('error')
        ->call('submit')
        ->assertNotDispatched('error')
        ->assertRedirect();

    expect(Application::query()->latest('id')->value('git_repository'))->toBe('https://gitlab.com/gitlab-org/gitlab-test.git');
});

it('refuses repository addresses that could reach the platform network', function () {
    $world = journeyWorld();

    journeyNewResource(PublicGitRepository::class, $world)
        ->set('repository_url', 'https://10.20.0.5/owner/repo.git')
        ->call('loadBranch')
        ->assertDispatched('error');

    expect(Application::query()->count())->toBe(0);
});

it('creates applications from a Docker image and from a Dockerfile', function () {
    $world = journeyWorld();

    journeyNewResource(DockerImage::class, $world)
        ->set('dockerImage', 'ghcr.io/coollabsio/coolify-examples:latest')
        ->call('submit')
        ->assertNotDispatched('error')
        ->assertRedirect();
    journeyNewResource(SimpleDockerfile::class, $world)
        ->set('dockerfile', "FROM nginx:alpine\nEXPOSE 80\n")
        ->call('submit')
        ->assertNotDispatched('error')
        ->assertRedirect();

    expect(Application::query()->pluck('build_pack')->sort()->values()->all())->toBe(['dockerfile', 'dockerimage']);
});

it('creates every kind of database from the catalog', function () {
    $world = journeyWorld();
    $failures = [];

    foreach (DATABASE_TYPES as $type) {
        $response = $this->get(route('project.resource.create', [
            'project_uuid' => $world['project']->uuid,
            'environment_uuid' => $world['environment']->uuid,
            'type' => $type, 'destination' => $world['destination']->uuid, 'server_id' => $world['node']->id,
            'database_image' => $type === 'postgresql' ? 'postgres:17-alpine' : null,
        ]));
        if (! $response->isRedirect() || ! str_contains((string) $response->headers->get('Location'), '/database/')) {
            $failures[] = "{$type}: ".journeyDescribe($response);
        }
    }

    expect($failures)->toBe([]);
});

it('creates every one-click service offered to customers, ready to deploy', function () {
    $world = journeyWorld();
    config(['platform.plans.pro.quotas.resources' => 0]);
    $failures = [];
    $offered = get_service_templates();
    expect($offered->count())->toBeGreaterThan(200);

    foreach ($offered->keys() as $name) {
        $response = journeyGet(route('project.resource.create', [
            'project_uuid' => $world['project']->uuid,
            'environment_uuid' => $world['environment']->uuid,
            'type' => "one-click-service-{$name}", 'destination' => $world['destination']->uuid, 'server_id' => $world['node']->id,
        ]));
        $service = Service::query()->latest('id')->first();
        if (! $response->isRedirect() || ! str_contains((string) $response->headers->get('Location'), '/service/') || is_null($service)) {
            $failures[] = "{$name}: ".journeyDescribe($response);
        } else {
            // What StartService writes on the node.
            try {
                PlatformGate::harden($service->refresh(), Yaml::parse((string) $service->docker_compose) ?? [], reserve: false);
            } catch (PlatformPolicyViolation $violation) {
                $failures[] = "{$name}: deployment refused: ".implode(' | ', $violation->violations);
            }
        }
        Service::query()->get()->each(fn (Service $service) => $service->forceDelete());
    }

    expect($failures)->toBe([]);
})->group('slow');

it('treats the MinIO domains Coolify generates as domains of the customer', function () {
    $world = journeyWorld();
    journeyGet(route('project.resource.create', [
        'project_uuid' => $world['project']->uuid, 'environment_uuid' => $world['environment']->uuid,
        'type' => 'one-click-service-minio', 'destination' => $world['destination']->uuid, 'server_id' => $world['node']->id,
    ]))->assertRedirect();
    $service = Service::query()->latest('id')->firstOrFail();
    $serverUrl = $service->environment_variables()->where('key', 'MINIO_SERVER_URL')->firstOrFail();

    expect($serverUrl->value)->toEndWith('.apps.example.com')
        ->and(DomainPolicy::hostsOwnedBy($world['team']))->toContain(DomainPolicy::hostOf($serverUrl->value))
        ->and(DomainPolicy::ownerOf((string) DomainPolicy::hostOf($serverUrl->value)))->toBe($world['team']->id);
    expect(fn () => $serverUrl->update(['value' => 'https://cloud.example.com']))->toThrow(PlatformPolicyViolation::class, 'reserved');
});

it('does not offer the one-click services the platform can not run', function () {
    $world = journeyWorld();
    $templates = collect(json_decode(File::get(base_path('templates/'.config('constants.services.file_name')))));
    $unavailable = ServiceTemplates::unavailable($templates);

    // The Docker socket, host networking or capabilities, public TCP ports only.
    expect($unavailable)->toHaveKeys(['portainer', 'glances', 'cloudflared', 'wireguard-easy', 'minecraft'])
        ->and(get_service_templates()->keys()->intersect(array_keys($unavailable))->all())->toBe([])
        ->and(count($unavailable))->toBeLessThan(45);

    // A link to one of them creates nothing.
    $response = journeyGet(route('project.resource.create', [
        'project_uuid' => $world['project']->uuid, 'environment_uuid' => $world['environment']->uuid,
        'type' => 'one-click-service-portainer', 'destination' => $world['destination']->uuid, 'server_id' => $world['node']->id,
    ]));
    expect($response->status())->toBe(200)->and(Service::query()->count())->toBe(0);
});

it('refuses a compose stack the platform can not run before creating anything', function () {
    $world = journeyWorld();

    expect(fn () => Service::create([
        'docker_compose_raw' => "services:\n  agent:\n    image: 'portainer/agent:2.21'\n    volumes:\n      - '/var/run/docker.sock:/var/run/docker.sock'\n",
        'environment_id' => $world['environment']->id,
        'server_id' => $world['node']->id,
        'destination_id' => $world['destination']->id,
        'destination_type' => $world['destination']->getMorphClass(),
    ]))->toThrow(PlatformPolicyViolation::class, 'Docker socket');
    expect(Service::query()->count())->toBe(0);
});

it('tells the customer about plan limits instead of failing the page', function () {
    $world = journeyWorld();
    config(['platform.plans.pro.quotas.resources' => 1]);
    $link = fn (string $type) => route('project.resource.create', [
        'project_uuid' => $world['project']->uuid, 'environment_uuid' => $world['environment']->uuid,
        'type' => $type, 'destination' => $world['destination']->uuid, 'server_id' => $world['node']->id,
        'database_image' => 'postgres:17-alpine',
    ]);

    journeyGet($link('postgresql'))->assertRedirect();
    foreach (['redis', 'one-click-service-uptime-kuma'] as $type) {
        journeyGet($link($type))
            ->assertOk()
            ->assertSee('Plan limit reached')
            ->assertSee('Your plan allows 1 resources');
    }
    expect(Service::query()->count())->toBe(0)
        ->and(\App\Models\StandaloneRedis::query()->count())->toBe(0);
});

it('opens every page of a customer without server errors', function () {
    $world = journeyWorld();
    journeyGithubApi();
    journeyNewResource(PublicGitRepository::class, $world)
        ->set('repository_url', 'https://github.com/BAD-WOLF/StreamVibe')
        ->call('loadBranch')
        ->call('submit');
    $application = Application::query()->latest('id')->firstOrFail();
    $this->get(route('project.resource.create', [
        'project_uuid' => $world['project']->uuid, 'environment_uuid' => $world['environment']->uuid,
        'type' => 'postgresql', 'destination' => $world['destination']->uuid, 'server_id' => $world['node']->id,
        'database_image' => 'postgres:17-alpine',
    ]));
    $database = \App\Models\StandalonePostgresql::query()->latest('id')->firstOrFail();
    $this->get(route('project.resource.create', [
        'project_uuid' => $world['project']->uuid, 'environment_uuid' => $world['environment']->uuid,
        'type' => 'one-click-service-uptime-kuma', 'destination' => $world['destination']->uuid, 'server_id' => $world['node']->id,
    ]));
    $service = Service::query()->latest('id')->firstOrFail();

    // Compiled now, with the platform name.
    Artisan::call('view:clear');
    $failures = journeySweep([
        'project_uuid' => $world['project']->uuid,
        'environment_uuid' => $world['environment']->uuid,
        'application_uuid' => $application->uuid,
        'database_uuid' => $database->uuid,
        'service_uuid' => $service->uuid,
        'stack_service_uuid' => $service->applications()->first()?->uuid,
    ], $coolify);
    Artisan::call('view:clear');

    expect($failures)->toBe([])
        ->and($coolify)->toBe([]);
});

it('opens every page of the operator without server errors', function () {
    $world = journeyWorld();
    $owner = User::factory()->create(['name' => 'Owner']);
    Team::find(0)->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => Team::find(0)]);
    Cache::forget('team:'.$owner->id);
    config(['platform.security.require_two_factor' => 'none', 'platform.version' => 'platform-v1.4.0']);

    Artisan::call('view:clear');
    $failures = journeySweep([
        'server_uuid' => $world['node']->uuid,
        'private_key_uuid' => $world['node']->privateKey->uuid,
        'destination_uuid' => $world['destination']->uuid,
    ], $coolify);
    $dashboard = journeyGet(route('dashboard'));
    $updates = journeyGet(route('settings.updates'));
    Artisan::call('view:clear');

    expect($failures)->toBe([])
        ->and($coolify)->toBe([])
        // The release of the platform, not the Coolify version; updates come with the releases.
        ->and($dashboard->getContent())->toContain('v1.4.0')->not->toContain(config('constants.coolify.version'))
        ->and($updates->isRedirect(route('settings.index')))->toBeTrue();
});

it('keeps the instance settings to the operator', function () {
    journeyWorld();

    foreach (['settings.index', 'settings.advanced', 'settings.updates', 'settings.backup', 'settings.email', 'settings.oauth'] as $page) {
        expect(journeyGet(route($page))->isRedirect(route('dashboard')))->toBeTrue("{$page} opens for a customer");
    }
});

/**
 * Laravel's own "redirect" binding, kept before any Livewire component replaces it.
 *
 * @param  array{concrete: \Closure, shared: bool}|null  $binding
 * @return array{concrete: \Closure, shared: bool}
 */
function journeyRedirector(?array $binding = null): array
{
    static $original;
    if ($binding) {
        $original = $binding;
    }

    return $original;
}

/**
 * A GET from a fresh request: one process serves every request of a test, and a page that stops in
 * the middle of a Livewire lifecycle leaves Livewire's redirector bound, which would break the
 * next pages (in production every request starts clean).
 */
function journeyGet(string $url): \Illuminate\Testing\TestResponse
{
    $redirect = journeyRedirector();
    \Livewire\Features\SupportRedirects\SupportRedirects::$redirectorCacheStack = [];
    app()->forgetInstance('redirect');
    app()->bind('redirect', $redirect['concrete'], $redirect['shared']);

    return test()->get($url);
}

/**
 * Status, redirect and the exception (with the first frame of this application) of a response.
 */
function journeyDescribe(\Illuminate\Testing\TestResponse $response): string
{
    $description = "HTTP {$response->status()} ".(string) $response->headers->get('Location');
    $exception = $response->exception;
    if ($exception) {
        $frame = collect($exception->getTrace())->first(fn ($frame) => str_contains($frame['file'] ?? '', '/app/') || str_contains($frame['file'] ?? '', '/bootstrap/helpers/'));
        $where = str($exception->getFile())->after(base_path().'/').':'.$exception->getLine()
            .($frame ? ' via '.str($frame['file'])->after(base_path().'/').':'.$frame['line'] : '');
        $description .= ' '.class_basename($exception).': '.str($exception->getMessage())->limit(180)." @ {$where}";
    }

    return $description;
}

/**
 * GETs every named page whose parameters are known and lists the ones that answer with a server
 * error.
 *
 * @param  array<string, ?string>  $parameters
 * @return list<string>
 */
function journeySweep(array $parameters, ?array &$coolify = null): array
{
    $failures = [];
    $coolify = [];
    $skip = ['logout', 'verify.verify', 'password.reset', 'auth.link', 'auth.redirect', 'auth.callback', 'download.backup', 'feedback', 'upgrade'];
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if (is_null($name) || ! in_array('GET', $route->methods(), true) || in_array($name, $skip, true)
            || str_starts_with($route->uri(), 'api/') || str_starts_with($name, 'livewire.') || str_starts_with($name, 'horizon.')
            || str_starts_with($name, 'telescope') || str_contains($route->uri(), 'webhooks')) {
            continue;
        }
        $values = [];
        foreach ($route->parameterNames() as $parameter) {
            if (! filled($parameters[$parameter] ?? null)) {
                continue 2;
            }
            $values[$parameter] = $parameters[$parameter];
        }
        $response = journeyGet(route($name, $values));
        if ($response->status() >= 500) {
            $failures[] = "{$name}: ".journeyDescribe($response);
        } elseif ($response->status() === 200 && str_contains((string) $response->headers->get('Content-Type'), 'html')) {
            foreach (journeyCoolifyMentions((string) $response->getContent()) as $mention) {
                $coolify[] = "{$name}: {$mention}";
            }
        }
    }

    return $failures;
}

/**
 * Where a page shows the Coolify name or logo to the person reading it: text, page title,
 * tooltips and placeholders, images. Scripts, styles and Livewire/Alpine state are not read.
 *
 * @return array<int, string>
 */
function journeyCoolifyMentions(string $html): array
{
    $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html);
    $html = (string) preg_replace('/\s(?:wire:[\w.-]+|x-[\w.:-]+|@[\w.:-]+|:[\w.-]+)="[^"]*"/', ' ', $html);
    $mentions = [];
    $text = html_entity_decode(strip_tags($html));
    if (preg_match_all('/[^\n]{0,40}\bCoolify\b[^\n]{0,40}/u', $text, $matches)) {
        $mentions = array_merge($mentions, array_map('trim', $matches[0]));
    }
    if (preg_match_all('/\s(?:title|alt|placeholder|aria-label|content|helper)="([^"]*\bCoolify\b[^"]*)"/', $html, $matches)) {
        $mentions = array_merge($mentions, $matches[1]);
    }
    if (preg_match_all('/[\w\/.-]*coolify-logo[\w.-]*/', $html, $matches)) {
        $mentions = array_merge($mentions, $matches[0]);
    }
    // Links and examples pointing to the Coolify sites.
    if (preg_match_all('#\s(?:href|src)="(https?://(?:[\w-]+\.)*(?:coolify\.io|coollabs\.io)[^"]*|https?://github\.com/coollabsio[^"]*)"#', $html, $matches)) {
        $mentions = array_merge($mentions, $matches[1]);
    }
    if (preg_match_all('/[^\n"]{0,30}coolify\.io[^\n"]{0,30}/u', $text, $matches)) {
        $mentions = array_merge($mentions, array_map('trim', $matches[0]));
    }
    if (preg_match_all('/\splaceholder="([^"]*(?:coolify\.io|Coolify)[^"]*)"/', $html, $matches)) {
        $mentions = array_merge($mentions, $matches[1]);
    }

    return array_values(array_unique($mentions));
}
