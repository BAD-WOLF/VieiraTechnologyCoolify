<?php

use App\Actions\Platform\ProvisionTenant;
use App\Models\AggregatedUsageHourly;
use App\Models\InstanceSettings;
use App\Models\PlatformInvoice;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\PlatformWebhook;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.billing.provider' => 'efi',
        'platform.plans.pro.price_monthly' => '149.90',
        'platform.security.operator_api_cidrs' => [],
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.com.br']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
    // Public address for the webhook hosts, without a DNS lookup.
    WebhookUrlGuard::resolveUsing(fn () => ['93.184.216.34']);
});

afterEach(fn () => WebhookUrlGuard::resolveUsing(null));

/**
 * A customer (or the operator, with team 0) and an API token of its team.
 *
 * @param  array<int, string>  $abilities
 * @return array{user: User, team: Team, token: string, headers: array<string, string>}
 */
function apiClient(?int $teamId = null, array $abilities = ['read', 'write']): array
{
    $user = User::factory()->create();
    $team = is_null($teamId) ? $user->teams()->first() : Team::find($teamId);
    if (! is_null($teamId)) {
        $team->members()->attach($user->id, ['role' => 'owner']);
    }
    session(['currentTeam' => $team]);
    $token = $user->createToken('api', $abilities)->plainTextToken;
    app('auth')->forgetGuards();

    return ['user' => $user, 'team' => $team, 'token' => $token, 'headers' => ['Authorization' => 'Bearer '.$token]];
}

it('shows a customer its usage, limits and a preview of the next invoice', function () {
    $client = apiClient();
    $tenant = PlatformTenant::forTeam($client['team']);
    $tenant->update(['plan' => 'pro', 'status' => PlatformTenant::STATUS_ACTIVE, 'billing_interval' => 'monthly',
        'current_period_start' => now()->subDays(10), 'current_period_end' => now()->addDays(20)]);
    foreach ([now()->subDays(3), now()->subDays(40)] as $hour) {
        AggregatedUsageHourly::query()->create(['team_id' => $tenant->team_id, 'metric' => 'cpu_seconds', 'value' => 7200, 'samples' => 60,
            'period_start' => $hour->copy()->startOfHour(), 'period_end' => $hour->copy()->startOfHour()->addHour()]);
    }
    $other = apiClient();
    AggregatedUsageHourly::query()->create(['team_id' => $other['team']->id, 'metric' => 'cpu_seconds', 'value' => 1, 'samples' => 1,
        'period_start' => now()->subDay()->startOfHour(), 'period_end' => now()->subDay()->startOfHour()->addHour()]);

    $response = $this->getJson('/api/v1/platform/usage', $client['headers'])->assertOk();

    $response->assertJsonPath('plan', 'pro')
        ->assertJsonPath('status', PlatformTenant::STATUS_ACTIVE)
        ->assertJsonStructure(['period' => ['start', 'end'], 'quotas', 'current', 'invoice_preview' => ['currency', 'amount_cents', 'lines'], 'hourly']);
    expect($response->json('hourly'))->toHaveCount(1)
        ->and((float) $response->json('hourly.0.value'))->toBe(7200.0);

    app('auth')->forgetGuards();
    $wide = $this->getJson('/api/v1/platform/usage?from='.now()->subDays(60)->toDateString(), $client['headers'])->assertOk();
    expect($wide->json('hourly'))->toHaveCount(2);
});

it('lists only the invoices and the audit trail of the team of the token', function () {
    $client = apiClient();
    $tenant = PlatformTenant::forTeam($client['team']);
    $mine = PlatformBilling::createInvoice($tenant, PlatformInvoice::TYPE_SUBSCRIPTION, 'pro', 'monthly', now(), now()->addMonth(), [PlatformBilling::planLine('pro', 'monthly', 14990)]);
    $other = apiClient();
    PlatformBilling::createInvoice(PlatformTenant::forTeam($other['team']), PlatformInvoice::TYPE_SUBSCRIPTION, 'pro', 'monthly', now(), now()->addMonth(), [PlatformBilling::planLine('pro', 'monthly', 14990)]);
    audit_log('application.deployed', null, ['name' => 'loja'], $client['team']->id);
    audit_log('application.deployed', null, ['name' => 'segredo-do-outro'], $other['team']->id);

    $invoices = $this->getJson('/api/v1/platform/invoices', $client['headers'])->assertOk()->json();
    expect($invoices)->toHaveCount(1)
        ->and($invoices[0]['uuid'])->toBe($mine->uuid)
        ->and($invoices[0]['amount_cents'])->toBe(14990)
        ->and($invoices[0])->not->toHaveKey('metadata')
        ->and($invoices[0])->not->toHaveKey('payment_details');

    app('auth')->forgetGuards();
    $logs = $this->getJson('/api/v1/platform/audit-logs', $client['headers'])->assertOk()->json();
    expect(collect($logs)->pluck('metadata.name')->filter()->values()->all())->toContain('loja')
        ->not->toContain('segredo-do-outro')
        ->and(collect($logs)->pluck('team_id')->filter()->all())->toBe([]);
});

it('answers only customers of the platform with valid tokens', function () {
    $operator = apiClient(0);
    foreach (['/api/v1/platform/usage', '/api/v1/platform/invoices', '/api/v1/platform/logs?resource_uuid=x'] as $url) {
        app('auth')->forgetGuards();
        $this->getJson($url, $operator['headers'])->assertNotFound()->assertJsonPath('message', 'Only available for platform tenants.');
    }

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/platform/usage')->assertUnauthorized();
    $this->getJson('/api/v1/platform/usage', ['Authorization' => 'Bearer 1|nao-existe'])->assertUnauthorized();
});

it('hides the webhook API outside the platform', function () {
    $client = apiClient();
    config(['platform.enabled' => false]);

    $this->getJson('/api/v1/platform/webhooks', $client['headers'])->assertNotFound();
});

it('lets only the operator queue the provisioning of a customer', function () {
    $customer = apiClient();
    $tenantTeam = $customer['team']->id;
    $operator = apiClient(0);

    $this->postJson("/api/v1/platform/tenants/{$tenantTeam}/provision", [], $operator['headers'])
        ->assertOk()->assertJsonPath('message', 'Provisioning queued.');
    Queue::assertPushed(JobDecorator::class, fn ($job) => $job->getAction() instanceof ProvisionTenant && $job->getParameters() === [$tenantTeam]);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/platform/tenants/0/provision', [], $operator['headers'])
        ->assertUnprocessable()->assertJsonPath('message', 'The operator team is not a tenant.');

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/platform/tenants/{$tenantTeam}/provision", [], $customer['headers'])
        ->assertForbidden()->assertJsonPath('message', 'Only the platform operator can provision tenants.');

    app('auth')->forgetGuards();
    $readOnly = apiClient(0, ['read']);
    $this->postJson("/api/v1/platform/tenants/{$tenantTeam}/provision", [], $readOnly['headers'])->assertForbidden();
    Queue::assertPushed(JobDecorator::class, 1);
});

it('registers a node in a region with its wildcard domain, for the operator only', function () {
    PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo']);
    $node = Server::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'node-1', 'ip' => '10.20.8.10', 'team_id' => 0, 'private_key_id' => 1]);
    $operator = apiClient(0);

    $this->postJson("/api/v1/platform/servers/{$node->uuid}/region", ['region' => 'sa-east-1', 'wildcard_domain' => 'https://apps.hostrema.com.br/'], $operator['headers'])
        ->assertOk();
    expect($node->fresh()->settings->wildcard_domain)->toBe('https://apps.hostrema.com.br');

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/platform/servers/nao-existe/region', ['region' => 'sa-east-1'], $operator['headers'])
        ->assertNotFound()->assertJsonPath('message', 'Server not found.');

    app('auth')->forgetGuards();
    $customer = apiClient();
    $this->postJson("/api/v1/platform/servers/{$node->uuid}/region", ['region' => 'sa-east-1'], $customer['headers'])
        ->assertForbidden()->assertJsonPath('message', 'Only the platform operator can manage nodes.');
});

it('lists, creates, tests and deletes the webhooks of the team of the token', function () {
    $client = apiClient();
    $other = PlatformWebhook::create(['team_id' => 0, 'url' => 'https://hooks.example.com/root', 'events' => ['*'], 'secret' => 'whsec_root']);
    app('auth')->forgetGuards();

    $created = $this->postJson('/api/v1/platform/webhooks', ['url' => 'https://hooks.example.com/ci', 'events' => ['deployment.finished']], $client['headers'])
        ->assertCreated();
    $uuid = $created->json('uuid');
    expect($created->json('secret'))->toStartWith('whsec_');

    app('auth')->forgetGuards();
    $list = $this->getJson('/api/v1/platform/webhooks', $client['headers'])->assertOk()->json();
    expect(collect($list)->pluck('uuid')->all())->toBe([$uuid])
        ->and($list[0])->not->toHaveKey('secret');

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/platform/webhooks/{$uuid}/test", [], $client['headers'])->assertSuccessful();
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/platform/webhooks/{$other->uuid}/test", [], $client['headers'])->assertNotFound();
    app('auth')->forgetGuards();
    $this->deleteJson("/api/v1/platform/webhooks/{$other->uuid}", [], $client['headers'])->assertNotFound();
    app('auth')->forgetGuards();
    $this->deleteJson("/api/v1/platform/webhooks/{$uuid}", [], $client['headers'])->assertSuccessful();
    expect(PlatformWebhook::query()->where('uuid', $uuid)->exists())->toBeFalse()
        ->and($other->fresh())->not->toBeNull();
});
