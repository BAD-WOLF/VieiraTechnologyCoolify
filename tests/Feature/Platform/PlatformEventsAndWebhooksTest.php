<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Events\PlatformEventOccurred;
use App\Jobs\PlatformWebhookDeliveryJob;
use App\Livewire\Platform\Webhooks as WebhooksComponent;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\PlatformWebhook;
use App\Models\PlatformWebhookDelivery;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\PlatformEvents;
use App\Services\Platform\Webhooks;
use App\Services\Platform\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.events.stream' => 'hostrema-test:events',
        'platform.webhooks.allow_http' => false,
        'platform.webhooks.disable_after_failures' => 50,
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://cloud.example.com']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
    WebhookUrlGuard::resolveUsing(fn (string $host) => match ($host) {
        'hooks.example.com' => ['93.184.216.34'],
        'evil.example.com' => ['93.184.216.34', '10.0.0.5'],
        'metadata.example.com' => ['169.254.169.254'],
        default => [],
    });
    try {
        Redis::connection()->client()->rawCommand('DEL', 'hostrema-test:events');
    } catch (\Throwable) {
    }
});

afterEach(fn () => WebhookUrlGuard::resolveUsing(null));

/**
 * @return array{user: User, team: Team, application: Application, node: Server, destination: StandaloneDocker}
 */
function eventsWorld(): array
{
    $region = PlatformRegion::query()->firstOrCreate(['slug' => 'sa-east-1'], ['name' => 'São Paulo', 'is_default' => true]);
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

    return ['user' => $user, 'team' => $team, 'application' => $application, 'node' => $node, 'destination' => $destination];
}

it('keeps the own model listeners of Coolify running in platform mode', function () {
    $world = eventsWorld();
    expect(ApplicationSetting::query()->where('application_id', $world['application']->id)->exists())->toBeTrue();

    $database = StandalonePostgresql::create([
        'name' => 'db', 'postgres_user' => 'app', 'postgres_password' => 'secret-password', 'postgres_db' => 'app',
        'environment_id' => $world['application']->environment_id,
        'destination_id' => $world['destination']->id, 'destination_type' => StandaloneDocker::class,
    ]);
    expect(LocalPersistentVolume::query()->where('resource_type', $database->getMorphClass())->where('resource_id', $database->id)->exists())->toBeTrue();
});

it('repairs applications and databases created without their Coolify listeners', function () {
    $world = eventsWorld();
    ApplicationSetting::query()->where('application_id', $world['application']->id)->delete();
    $database = StandalonePostgresql::withoutEvents(fn () => StandalonePostgresql::create([
        'uuid' => (string) new Cuid2, 'name' => 'db', 'postgres_user' => 'app', 'postgres_password' => 'secret-password', 'postgres_db' => 'app',
        'environment_id' => $world['application']->environment_id,
        'destination_id' => $world['destination']->id, 'destination_type' => StandaloneDocker::class,
    ]));
    $before = PlatformWebhookDelivery::query()->count();

    $this->artisan('platform:repair-resources')->assertFailed();
    $this->artisan('platform:repair-resources', ['--fix' => true])->assertSuccessful();

    expect(ApplicationSetting::query()->where('application_id', $world['application']->id)->count())->toBe(1)
        ->and(LocalPersistentVolume::query()->where('resource_type', $database->getMorphClass())->where('resource_id', $database->id)->count())->toBe(1)
        ->and(PlatformWebhookDelivery::query()->count())->toBe($before);
    $this->artisan('platform:repair-resources')->assertSuccessful();
});

it('accepts only public HTTPS webhook targets', function () {
    expect(WebhookUrlGuard::validate('https://hooks.example.com/hostrema'))->toBeNull()
        ->and(WebhookUrlGuard::validate('http://hooks.example.com/hostrema'))->not->toBeNull()
        ->and(WebhookUrlGuard::validate('https://user:pass@hooks.example.com/'))->not->toBeNull()
        ->and(WebhookUrlGuard::validate('https://localhost/hook'))->not->toBeNull()
        ->and(WebhookUrlGuard::validate('https://evil.example.com/'))->not->toBeNull()
        ->and(WebhookUrlGuard::validate('https://metadata.example.com/'))->not->toBeNull()
        ->and(WebhookUrlGuard::validate('https://unknown.example.com/'))->not->toBeNull()
        ->and(WebhookUrlGuard::validate('https://10.0.0.5/'))->not->toBeNull()
        ->and(WebhookUrlGuard::validate("https://hooks.example.com/\nx"))->not->toBeNull();

    foreach (['127.0.0.1', '169.254.169.254', '100.64.1.1', '192.168.1.1', '::1', 'fd00::1', 'fe80::1', '::ffff:10.0.0.1', '64:ff9b::a00:1'] as $ip) {
        expect(WebhookUrlGuard::isPublicIp($ip))->toBeFalse();
    }
    expect(WebhookUrlGuard::isPublicIp('93.184.216.34'))->toBeTrue()
        ->and(WebhookUrlGuard::isPublicIp('2606:2800:220:1:248:1893:25c8:1946'))->toBeTrue();
});

it('validates the events and the operator-only options of a webhook', function () {
    $world = eventsWorld();
    $webhook = Webhooks::create($world['team']->id, 'https://hooks.example.com/a', ['deployment.*', 'resource.status_changed'], 'CI', true);

    expect($webhook->secret)->toStartWith('whsec_')
        ->and($webhook->all_teams)->toBeFalse()
        ->and($webhook->listensTo('deployment.failed'))->toBeTrue()
        ->and($webhook->listensTo('invoice.paid'))->toBeFalse()
        ->and($webhook->toArray())->not->toHaveKey('secret');

    expect(fn () => Webhooks::create($world['team']->id, 'https://hooks.example.com/b', ['deployment.exploded']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Webhooks::create($world['team']->id, 'https://hooks.example.com/b', []))->toThrow(InvalidArgumentException::class)
        ->and(Webhooks::create(0, 'https://hooks.example.com/ops', ['*'], null, true)->all_teams)->toBeTrue();

    config(['platform.webhooks.max_per_team' => 1]);
    expect(fn () => Webhooks::create($world['team']->id, 'https://hooks.example.com/c', ['*']))->toThrow(InvalidArgumentException::class);
});

it('publishes deployment events to Laravel, the Redis stream and the webhooks of the team', function () {
    Event::fake([PlatformEventOccurred::class]);
    $world = eventsWorld();
    $other = eventsWorld();
    $own = Webhooks::create($world['team']->id, 'https://hooks.example.com/own', ['deployment.*']);
    $foreign = Webhooks::create($other['team']->id, 'https://hooks.example.com/other', ['*']);
    $operator = Webhooks::create(0, 'https://hooks.example.com/ops', ['deployment.finished'], null, true);

    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $world['application']->id, 'deployment_uuid' => (string) new Cuid2, 'server_id' => $world['node']->id,
        'destination_id' => $world['destination']->id, 'commit' => 'abc123', 'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
    $deployment->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);

    Event::assertDispatched(PlatformEventOccurred::class, fn (PlatformEventOccurred $event) => $event->type === 'deployment.finished'
        && $event->teamId === $world['team']->id
        && $event->data['application']['uuid'] === $world['application']->uuid
        && $event->data['commit'] === 'abc123');

    expect(PlatformWebhookDelivery::query()->where('platform_webhook_id', $own->id)->pluck('event_type')->all())->toBe(['deployment.queued', 'deployment.finished'])
        ->and(PlatformWebhookDelivery::query()->where('platform_webhook_id', $foreign->id)->count())->toBe(0)
        ->and(PlatformWebhookDelivery::query()->where('platform_webhook_id', $operator->id)->pluck('event_type')->all())->toBe(['deployment.finished']);
    Queue::assertPushed(PlatformWebhookDeliveryJob::class, 3);

    $entries = Redis::connection()->client()->rawCommand('XRANGE', 'hostrema-test:events', '-', '+');
    $types = collect($entries)->map(function (array $entry) {
        $fields = $entry[1];
        for ($i = 0; $i < count($fields); $i += 2) {
            if ($fields[$i] === 'type') {
                return $fields[$i + 1];
            }
        }

        return null;
    })->filter()->values()->all();
    expect($types)->toContain('deployment.queued')->toContain('deployment.finished')->toContain('resource.created');
});

it('signs webhook deliveries and retries only temporary failures', function () {
    $world = eventsWorld();
    $webhook = Webhooks::create($world['team']->id, 'https://hooks.example.com/own', ['*']);
    $delivery = Webhooks::sendTest($webhook);

    Http::fake(['https://hooks.example.com/*' => Http::sequence()->push('ok', 200)->push('boom', 500)->push('nope', 404)]);

    (new PlatformWebhookDeliveryJob($delivery->id))->handle();
    expect($delivery->fresh()->status)->toBe(PlatformWebhookDelivery::STATUS_SUCCEEDED);
    Http::assertSent(function (HttpRequest $request) use ($webhook, $delivery) {
        preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $request->header('X-Hostrema-Signature')[0] ?? '', $match);

        return $request->header('X-Hostrema-Event')[0] === 'webhook.test'
            && $request->header('X-Hostrema-Delivery')[0] === $delivery->uuid
            && hash_equals(hash_hmac('sha256', $match[1].'.'.$request->body(), $webhook->secret), $match[2])
            && json_decode($request->body(), true)['type'] === 'webhook.test';
    });

    $retry = Webhooks::sendTest($webhook);
    expect(fn () => (new PlatformWebhookDeliveryJob($retry->id))->handle())->toThrow(RuntimeException::class);
    expect($retry->fresh()->status)->toBe(PlatformWebhookDelivery::STATUS_PENDING);

    config(['platform.webhooks.disable_after_failures' => 1]);
    $permanent = Webhooks::sendTest($webhook);
    (new PlatformWebhookDeliveryJob($permanent->id))->handle();
    expect($permanent->fresh())->status->toBe(PlatformWebhookDelivery::STATUS_FAILED)->response_status->toBe(404)
        ->and($webhook->fresh()->enabled)->toBeFalse();

    $webhook->update(['enabled' => true, 'url' => 'https://evil.example.com/']);
    $blocked = Webhooks::sendTest($webhook);
    (new PlatformWebhookDeliveryJob($blocked->id))->handle();
    expect($blocked->fresh()->status)->toBe(PlatformWebhookDelivery::STATUS_FAILED);
    Http::assertSentCount(3);
});

it('manages webhooks through the API for the token team only', function () {
    $world = eventsWorld();
    $other = eventsWorld();
    session(['currentTeam' => $world['team']]);
    $headers = ['Authorization' => 'Bearer '.$world['user']->createToken('ci', ['read', 'write'])->plainTextToken];

    $created = $this->postJson('/api/v1/platform/webhooks', ['url' => 'https://hooks.example.com/api', 'events' => ['deployment.*']], $headers)
        ->assertCreated()->assertJsonPath('events', ['deployment.*']);
    expect($created->json('secret'))->toStartWith('whsec_');

    $this->getJson('/api/v1/platform/webhooks', $headers)->assertOk()->assertJsonCount(1)->assertJsonMissingPath('0.secret');
    $this->postJson('/api/v1/platform/webhooks/'.$created->json('uuid').'/test', [], $headers)->assertAccepted();
    $this->postJson('/api/v1/platform/webhooks', ['url' => 'https://10.0.0.5/hook', 'events' => ['*']], $headers)->assertUnprocessable();
    $this->getJson('/api/v1/platform/event-types', $headers)->assertOk()->assertJsonFragment(['type' => 'deployment.failed']);

    $foreign = Webhooks::create($other['team']->id, 'https://hooks.example.com/other', ['*']);
    $this->deleteJson('/api/v1/platform/webhooks/'.$foreign->uuid, [], $headers)->assertNotFound();
    $this->deleteJson('/api/v1/platform/webhooks/'.$created->json('uuid'), [], $headers)->assertOk();
    expect(PlatformWebhook::query()->where('team_id', $world['team']->id)->count())->toBe(0);
});

it('lets team admins manage webhooks in the dashboard', function () {
    $world = eventsWorld();
    $this->actingAs($world['user']);
    session(['currentTeam' => $world['team']]);

    Livewire::test(WebhooksComponent::class)
        ->set('url', 'https://hooks.example.com/ui')
        ->set('groups', ['deployments', 'backups'])
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('whsec_')
        ->assertSee('https://hooks.example.com/ui');
    expect(PlatformWebhook::query()->where('team_id', $world['team']->id)->first()->events)->toBe(['deployment.*', 'backup.*']);

    Livewire::test(WebhooksComponent::class)->set('url', 'https://metadata.example.com/')->call('create')->assertHasErrors('url');

    $member = User::factory()->create();
    $world['team']->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $world['team']]);
    Livewire::test(WebhooksComponent::class)->set('url', 'https://hooks.example.com/member')->call('create');
    expect(PlatformWebhook::query()->where('team_id', $world['team']->id)->count())->toBe(1);
});

it('documents every platform API route in the OpenAPI document', function () {
    $document = $this->getJson('/api/v1/platform/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')->json();
    $documented = array_keys($document['paths']);

    $routes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => '/'.ltrim(str($route->uri())->after('api')->value(), '/'))
        ->filter(fn (string $uri) => preg_match('#^/v1/(platform|billing/webhooks|rollback)#', $uri))
        ->unique()->values();

    expect($routes)->not->toBeEmpty();
    foreach ($routes as $uri) {
        expect($documented)->toContain($uri);
    }
    expect(Yaml::parseFile(\App\Http\Controllers\PlatformOpenApiController::path())['components']['schemas']['PlatformEvent']['properties']['type']['enum'])
        ->toEqualCanonicalizing(array_keys(PlatformEvents::TYPES));
});
