<?php

use App\Actions\Platform\ProvisionTenant;
use App\Exceptions\TenantInputRejected;
use App\Jobs\PlatformNoticeJob;
use App\Jobs\PlatformWebhookDeliveryJob;
use App\Livewire\Platform\Admin;
use App\Livewire\Platform\AdminPlans;
use App\Livewire\Platform\AdminSettings;
use App\Livewire\Platform\Integrations;
use App\Livewire\Platform\Logs;
use App\Livewire\Platform\Observability;
use App\Livewire\Platform\Onboarding;
use App\Livewire\Platform\PlatformHelp;
use App\Livewire\Platform\Webhooks as WebhooksComponent;
use App\Models\AuditLog;
use App\Models\InstanceSettings;
use App\Models\PlatformPlan;
use App\Models\PlatformRegion;
use App\Models\PlatformSetting;
use App\Models\PlatformTenant;
use App\Models\PlatformWebhook;
use App\Models\PlatformWebhookDelivery;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PlatformConfig;
use App\Services\Platform\TenantLifecycle;
use App\Services\Platform\TenantTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('ssh-keys');
    Process::fake();
    $this->withoutVite();
    PlatformSetting::flushMemo();
    PlatformConfig::flush();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.locale' => 'en',
        'platform.billing.provider' => 'efi',
        'platform.support_email' => null,
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.com.br']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

afterEach(fn () => PlatformConfig::flush());

function consoleOperator(string $role = 'owner'): User
{
    // A team member logged in may not create teams (the personal team of a new user).
    auth()->forgetUser();
    $user = User::factory()->create(['name' => 'Operadora']);
    Team::find(0)->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => Team::find(0)]);

    return $user;
}

function consoleCustomer(string $plan = 'pro', string $status = PlatformTenant::STATUS_ACTIVE, string $role = 'owner'): PlatformTenant
{
    auth()->forgetUser();
    $owner = User::factory()->create(['name' => 'Maria']);
    $team = $owner->teams()->first();
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(['plan' => $plan, 'status' => $status, 'current_period_end' => now()->addMonth()]);
    $user = $owner;
    if ($role !== 'owner') {
        $user = User::factory()->create(['name' => 'Membro']);
        $team->members()->attach($user->id, ['role' => $role]);
    }
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    \Illuminate\Support\Facades\Cache::forget('team:'.$user->id);

    return $tenant->fresh();
}

function consoleNode(?PlatformRegion $region = null, ?int $dedicatedTo = null): Server
{
    $key = PrivateKey::query()->firstOrCreate(['name' => 'console-node', 'team_id' => 0], [
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    $node = Server::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => 'node-'.fake()->unique()->numberBetween(1, 9999),
        'ip' => '10.20.0.'.fake()->unique()->numberBetween(2, 250),
        'team_id' => 0,
        'private_key_id' => $key->id,
        'platform_region_id' => $region?->id,
        'platform_dedicated_team_id' => $dedicatedTo,
    ]);
    $node->settings->forceFill(['is_reachable' => true, 'is_usable' => true])->save();

    return $node->fresh();
}

/**
 * Teams whose provisioning was queued (ProvisionTenant runs as a queued action).
 *
 * @return array<int, int>
 */
function provisionedTeams(): array
{
    return Queue::pushed(\Lorisleiva\Actions\Decorators\JobDecorator::class)
        ->filter(fn ($job) => $job->getAction() instanceof ProvisionTenant)
        ->map(fn ($job) => (int) $job->getParameters()[0])
        ->values()->all();
}

function auditCount(string $action): int
{
    return AuditLog::query()->where('action', $action)->count();
}

it('creates regions with a valid slug, the first one as the default', function () {
    consoleOperator();

    Livewire::test(Admin::class)
        ->set('regionSlug', 'São Paulo!')
        ->set('regionName', '')
        ->call('createRegion')
        ->assertHasErrors(['regionSlug', 'regionName'])
        ->set('regionSlug', 'sa-east-1')
        ->set('regionName', 'São Paulo')
        ->call('createRegion')
        ->assertHasNoErrors()
        ->assertSet('regionSlug', '')
        ->assertDispatched('success', 'Region created.')
        ->set('regionSlug', 'us-east-1')
        ->set('regionName', 'Virgínia')
        ->call('createRegion');

    expect(PlatformRegion::query()->where('slug', 'sa-east-1')->value('is_default'))->toBeTrue()
        ->and(PlatformRegion::query()->where('slug', 'us-east-1')->value('is_default'))->toBeFalse()
        ->and(auditCount('platform.region.created'))->toBe(2);
});

it('makes a region the default (and active) and turns regions on and off', function () {
    consoleOperator();
    $first = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true, 'is_active' => true]);
    $second = PlatformRegion::create(['slug' => 'us-east-1', 'name' => 'Virgínia', 'is_default' => false, 'is_active' => false]);

    $component = Livewire::test(Admin::class)
        ->call('toggleRegion', $second->id, 'is_default');
    expect($second->fresh()->is_default)->toBeTrue()
        ->and($second->fresh()->is_active)->toBeTrue()
        ->and(PlatformRegion::defaultRegion()->is($second))->toBeTrue();

    $component->call('toggleRegion', $first->id, 'is_active');
    expect($first->fresh()->is_active)->toBeFalse();

    $component->call('toggleRegion', $first->id, 'slug');
    expect($first->fresh()->slug)->toBe('sa-east-1');

    expect(fn () => $component->call('toggleRegion', 999999, 'is_active'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('assigns a node to a region and a dedicated customer, and provisions the customers there', function () {
    $tenant = consoleCustomer();
    $tenant->update(['provisioned_at' => now()]);
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true, 'is_active' => true]);
    $tenant->update(['platform_region_id' => $region->id]);
    $pending = PlatformTenant::forTeam(User::factory()->create()->teams()->first());
    $pending->update(['platform_region_id' => $region->id]);
    $node = consoleNode();
    consoleOperator();

    Livewire::test(Admin::class, ['tab' => 'nodes'])
        ->set("nodeRegions.{$node->id}", (string) $region->id)
        ->set("nodeDedicatedTeams.{$node->id}", (string) $tenant->team_id)
        ->call('saveNode', $node->id)
        ->assertDispatched('success', 'Node saved.');

    expect($node->fresh()->platform_region_id)->toBe($region->id)
        ->and($node->fresh()->platform_dedicated_team_id)->toBe($tenant->team_id)
        ->and(auditCount('platform.node.assigned'))->toBe(1);
    // Only customers already provisioned in the region get the new node.
    expect(provisionedTeams())->toBe([$tenant->team_id]);

    $component = Livewire::test(Admin::class, ['tab' => 'nodes'])
        ->set("nodeRegions.{$node->id}", '')
        ->set("nodeDedicatedTeams.{$node->id}", '')
        ->call('saveNode', $node->id);
    expect($node->fresh()->platform_region_id)->toBeNull()
        ->and($node->fresh()->platform_dedicated_team_id)->toBeNull()
        ->and(provisionedTeams())->toBe([$tenant->team_id])
        ->and(fn () => $component->call('saveNode', 999999))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('queues provisioning and suspends, stops and reactivates a customer', function () {
    $tenant = consoleCustomer();
    consoleOperator();

    $component = Livewire::test(Admin::class)
        ->call('provision', $tenant->id)
        ->assertDispatched('success', 'Provisioning queued.');
    expect(provisionedTeams())->toBe([$tenant->team_id]);

    $component->call('suspend', $tenant->id)->assertDispatched('success', 'Tenant suspended.');
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_SUSPENDED)
        ->and($tenant->fresh()->suspension_reason)->toBe(TenantLifecycle::REASON_MANUAL);
    Queue::assertPushed(PlatformNoticeJob::class);

    $component->call('suspend', $tenant->id, true);
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_HARD_SUSPENDED)
        ->and($tenant->fresh()->hard_suspended_at)->not->toBeNull();

    $component->call('reactivate', $tenant->id)->assertDispatched('success', 'Tenant reactivated.');
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->fresh()->suspension_reason)->toBeNull();

    expect(fn () => $component->call('provision', 999999))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('edits a customer: limits and capabilities of its own, courtesy and notes, and activates a pending one', function () {
    $tenant = consoleCustomer('basic', PlatformTenant::STATUS_PENDING);
    $tenant->update(['current_period_end' => now()->subMonth()]);
    consoleOperator();
    $quota = array_key_first(PlanCatalog::QUOTAS);
    $feature = array_key_first(PlanCatalog::FEATURES);

    $component = Livewire::test(Admin::class)
        ->call('editTenant', $tenant->id)
        ->assertSet('editingPlan', 'basic')
        ->set('editingPlan', 'nao-existe')
        ->call('saveTenant')
        ->assertHasErrors(['editingPlan'])
        ->set('editingPlan', 'pro')
        ->set("editingQuotas.{$quota}", '-1')
        ->call('saveTenant')
        ->assertHasErrors(["editingQuotas.{$quota}"]);
    expect($tenant->fresh()->plan)->toBe('basic');

    $component->set("editingQuotas.{$quota}", '3')
        ->set("editingFeatures.{$feature}", '1')
        ->set('editingPeriodEnd', '')
        ->set('editingExempt', true)
        ->set('editingNotes', '  Cliente piloto  ')
        ->call('saveTenant')
        ->assertHasNoErrors()
        ->assertDispatched('success', 'Tenant saved.')
        ->assertSet('editingTenantId', null);

    $tenant->refresh();
    expect($tenant->plan)->toBe('pro')
        ->and($tenant->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->current_period_end->isFuture())->toBeTrue()
        ->and($tenant->current_period_end->diffInDays(now()->addMonth()))->toBeLessThan(2)
        ->and((float) data_get($tenant->quota_overrides, $quota))->toBe(3.0)
        ->and(data_get($tenant->quota_overrides, "features.{$feature}"))->toBeTrue()
        ->and($tenant->billing_exempt)->toBeTrue()
        ->and($tenant->operator_notes)->toBe('Cliente piloto')
        ->and(provisionedTeams())->toBe([$tenant->team_id]);

    // Back to the plan's values.
    Livewire::test(Admin::class)
        ->call('editTenant', $tenant->id)
        ->assertSet("editingFeatures.{$feature}", '1')
        ->set("editingQuotas.{$quota}", '')
        ->set("editingFeatures.{$feature}", '')
        ->set('editingNotes', '')
        ->set('editingPeriodEnd', now()->addYear()->format('Y-m-d'))
        ->call('saveTenant');
    expect($tenant->fresh()->quota_overrides)->toBeNull()
        ->and($tenant->fresh()->operator_notes)->toBeNull()
        ->and($tenant->fresh()->current_period_end->toDateString())->toBe(now()->addYear()->toDateString());
});

it('refuses to open the dashboard as another operator, and answers 404 for strangers', function () {
    $tenant = consoleCustomer();
    $other = consoleOperator('admin');
    $tenant->team->members()->attach($other->id, ['role' => 'member']);
    $operator = consoleOperator();

    Livewire::test(Admin::class)
        ->call('impersonate', $tenant->id, $other->id)
        ->assertDispatched('error', 'This user can not be accessed.')
        ->assertNoRedirect()
        ->call('impersonate', $tenant->id, $operator->id)
        ->assertNotFound();
});

it('blocks every console action for users outside the root team', function () {
    $tenant = consoleCustomer();
    $customer = auth()->user();
    $operator = consoleOperator();
    $actions = [
        ['createRegion'], ['toggleRegion', 1, 'is_active'], ['saveNode', 1], ['provision', $tenant->id],
        ['suspend', $tenant->id], ['reactivate', $tenant->id], ['editTenant', $tenant->id], ['saveTenant'], ['impersonate', $tenant->id, $customer->id],
    ];
    foreach ($actions as $action) {
        $this->actingAs($operator);
        session(['currentTeam' => Team::find(0)]);
        $component = Livewire::test(Admin::class);
        $this->actingAs($customer);
        session(['currentTeam' => $tenant->team]);
        $component->call(...$action)->assertForbidden();
    }
    expect(PlatformRegion::query()->count())->toBe(0)
        ->and($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and(provisionedTeams())->toBe([]);
});

it('lets only the owner of the root team change plans, and keeps at least one plan', function () {
    PlanCatalog::load();
    consoleOperator('admin');
    Livewire::test(AdminPlans::class)
        ->call('create')
        ->assertDispatched('error', 'Only the owner of the root team can change the plans.')
        ->assertSet('editing', null)
        ->call('save')
        ->call('move', 'pro', 1)
        ->call('confirmDelete', 'pro')
        ->assertSet('deleting', null);

    consoleOperator();
    $plans = PlatformPlan::query()->orderBy('position')->orderBy('id')->pluck('slug')->all();
    Livewire::test(AdminPlans::class)
        ->call('save')
        ->assertHasNoErrors()
        ->call('move', $plans[0], -1)
        ->call('move', end($plans), 1)
        ->call('move', 'nao-existe', 1);
    expect(PlatformPlan::query()->orderBy('position')->orderBy('id')->pluck('slug')->all())->toBe($plans);

    Livewire::test(AdminPlans::class)
        ->call('create')
        ->set('extras', [['text' => 'Suporte', 'help' => ''], ['text' => 'SLA', 'help' => 'x']])
        ->call('removeExtra', 0)
        ->assertSet('extras', [['text' => 'SLA', 'help' => 'x']]);

    PlatformPlan::query()->where('slug', '!=', $plans[0])->delete();
    Livewire::test(AdminPlans::class)
        ->call('confirmDelete', $plans[0])
        ->call('delete')
        ->assertDispatched('error', 'The platform needs at least one plan.');
    expect(PlatformPlan::query()->where('slug', $plans[0])->exists())->toBeTrue();

    Livewire::test(AdminPlans::class)->call('delete')->assertHasNoErrors();
});

it('refuses a list setting with an invalid item and does not treat a plain setting as a secret', function () {
    PlatformConfig::$enabledInTests = true;
    consoleOperator();

    Livewire::test(AdminSettings::class)
        ->set('form.platform__reserved_hosts', 'www, -invalido-')
        ->call('save')
        ->assertHasErrors(['form.platform__reserved_hosts'])
        ->call('saveSecret', 'platform__name')
        ->call('generateSecret', 'platform__name')
        ->assertNotDispatched('success');

    expect(PlatformConfig::isOverridden('platform.reserved_hosts'))->toBeFalse()
        ->and(PlatformConfig::isOverridden('platform.name'))->toBeFalse();
    PlatformConfig::$enabledInTests = false;
});

it('shows payments as ready with Stripe only when Stripe has a key', function () {
    consoleOperator();
    config(['platform.billing.provider' => 'stripe', 'subscription.stripe_api_key' => null]);

    $payments = fn () => collect(Livewire::test(AdminSettings::class)->viewData('checklist'))->firstWhere('label', 'Payments');
    expect($payments()['ok'])->toBeFalse();

    config(['subscription.stripe_api_key' => 'sk_test_x']);
    expect($payments()['ok'])->toBeTrue();
});

it('lets team admins toggle, test, rotate, resend and delete their webhooks, and nobody else', function () {
    $tenant = consoleCustomer();
    $webhook = PlatformWebhook::create(['team_id' => $tenant->team_id, 'url' => 'https://hooks.example.com/hostrema?token=abc', 'events' => ['*'], 'secret' => 'whsec_old', 'enabled' => false, 'consecutive_failures' => 7, 'disabled_reason' => 'failing']);
    $delivery = PlatformWebhookDelivery::create(['uuid' => (string) new Cuid2, 'platform_webhook_id' => $webhook->id, 'event_id' => 'evt_1', 'event_type' => 'deployment.finished', 'payload' => ['id' => 'evt_1'], 'status' => 'failed']);
    $strangers = PlatformWebhook::create(['team_id' => 0, 'url' => 'https://hooks.example.com/root', 'events' => ['*'], 'secret' => 'whsec_root']);

    $component = Livewire::test(WebhooksComponent::class)
        ->call('toggle', $webhook->uuid);
    expect($webhook->fresh()->enabled)->toBeTrue()
        ->and($webhook->fresh()->consecutive_failures)->toBe(0)
        ->and($webhook->fresh()->disabled_reason)->toBeNull();

    $component->call('test', $webhook->uuid)->assertDispatched('success', 'Test event queued.');
    Queue::assertPushed(PlatformWebhookDeliveryJob::class);

    $component->call('rotate', $webhook->uuid)
        ->assertSet('newSecretFor', $webhook->uuid);
    expect($webhook->fresh()->secret)->not->toBe('whsec_old')
        ->and($component->get('newSecret'))->toBe($webhook->fresh()->secret);

    $before = PlatformWebhookDelivery::query()->where('platform_webhook_id', $webhook->id)->count();
    $component->call('resend', $delivery->uuid)->assertDispatched('success', 'Delivery queued again.');
    expect(PlatformWebhookDelivery::query()->where('platform_webhook_id', $webhook->id)->count())->toBe($before + 1)
        ->and(PlatformWebhookDelivery::query()->latest('id')->first()->payload)->toBe(['id' => 'evt_1']);

    // Webhooks of other teams are not found.
    $component->call('toggle', $strangers->uuid)->call('delete', $strangers->uuid);
    expect($strangers->fresh())->not->toBeNull();

    $component->call('delete', $webhook->uuid)->assertDispatched('success', 'Webhook deleted.');
    expect($webhook->fresh())->toBeNull()
        ->and(AuditLog::query()->where('action', 'webhook.deleted')->value('metadata')['url'])->toBe('https://hooks.example.com/hostrema');

    $member = PlatformWebhook::create(['team_id' => $tenant->team_id, 'url' => 'https://hooks.example.com/b', 'events' => ['*'], 'secret' => 'whsec_b']);
    $memberDelivery = PlatformWebhookDelivery::create(['uuid' => (string) new Cuid2, 'platform_webhook_id' => $member->id, 'event_id' => 'evt_2', 'event_type' => 'x', 'payload' => [], 'status' => 'failed']);
    $tenant->team->members()->attach(($user = User::factory()->create())->id, ['role' => 'member']);
    $this->actingAs($user);
    Livewire::test(WebhooksComponent::class)
        ->call('toggle', $member->uuid)
        ->assertDispatched('error', 'Only team admins can manage webhooks.')
        ->call('resend', $memberDelivery->uuid)
        ->call('delete', $member->uuid);
    expect($member->fresh())->not->toBeNull()
        ->and($member->fresh()->enabled)->toBeTrue();
});

it('sends a blocked customer to the subscription page, and lets the limited one onboard', function () {
    consoleCustomer('pro', PlatformTenant::STATUS_SUSPENDED);
    Livewire::test(Onboarding::class)->assertRedirect(route('subscription.index'));

    consoleCustomer('pro', PlatformTenant::STATUS_LIMITED);
    Livewire::test(Onboarding::class)->assertNoRedirect();

    consoleOperator();
    Livewire::test(Onboarding::class)->assertRedirect(route('onboarding'));
});

it('finishes the onboarding with the first project in the chosen region, for owners and admins', function () {
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true, 'is_active' => true]);
    $tenant = consoleCustomer('pro', PlatformTenant::STATUS_ACTIVE, 'member');

    Livewire::test(Onboarding::class)
        ->call('finish')
        ->assertDispatched('error', 'Only team owners and admins can finish the onboarding.');
    expect(Project::query()->where('team_id', $tenant->team_id)->exists())->toBeFalse();

    $tenant = consoleCustomer();
    Livewire::test(Onboarding::class)
        ->set('projectName', '<script>')
        ->call('finish')
        ->assertHasErrors(['projectName'])
        ->set('projectName', 'Loja da Maria')
        ->call('finish')
        ->assertRedirectContains('/project/');

    $project = Project::query()->where('team_id', $tenant->team_id)->first();
    expect($project->name)->toBe('Loja da Maria')
        ->and($tenant->fresh()->platform_region_id)->toBe($region->id)
        ->and(Team::find($tenant->team_id)->show_boarding)->toBeFalse();

    // A region turned off is not offered; asking for it finds nothing.
    $inactive = PlatformRegion::create(['slug' => 'us-east-1', 'name' => 'Virgínia', 'is_default' => false, 'is_active' => false]);
    $other = consoleCustomer();
    Livewire::test(Onboarding::class)
        ->set('regionId', $inactive->id)
        ->call('finish')
        ->assertNotFound();
    expect(Project::query()->where('team_id', $other->team_id)->exists())->toBeFalse();
});

it('lets a customer skip the onboarding', function () {
    $tenant = consoleCustomer();

    Livewire::test(Onboarding::class)
        ->call('skip')
        ->assertRedirect(route('dashboard'));

    expect(Team::find($tenant->team_id)->show_boarding)->toBeFalse();
});

it('creates the network of the customer on its nodes, and only on reachable ones', function () {
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true, 'is_active' => true]);
    $node = consoleNode($region);
    $offline = consoleNode($region);
    $offline->settings->forceFill(['is_reachable' => false])->save();
    $tenant = consoleCustomer();

    ProvisionTenant::run($tenant->team_id);

    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'docker network create --driver bridge --attachable')
        && str_contains((string) $process->command, "tenant-{$tenant->team_id}"));
    expect(StandaloneDocker::query()->where('team_id', $tenant->team_id)->where('server_id', $node->id)->exists())->toBeTrue()
        ->and(StandaloneDocker::query()->where('team_id', $tenant->team_id)->where('server_id', $offline->id)->exists())->toBeTrue();
});

it('uses only the nodes dedicated to a customer when it has them', function () {
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true, 'is_active' => true]);
    $tenant = consoleCustomer();
    $shared = consoleNode($region);
    $dedicated = consoleNode($region, $tenant->team_id);

    ProvisionTenant::run($tenant->team_id, null, false);

    expect(StandaloneDocker::query()->where('team_id', $tenant->team_id)->pluck('server_id')->all())->toBe([$dedicated->id]);
});

it('refuses to provision without an active region', function () {
    $tenant = consoleCustomer();

    expect(fn () => ProvisionTenant::run($tenant->team_id))->toThrow(\RuntimeException::class, 'No active platform region');
});

it('only lets customers with traces in the plan create a token, and lets them revoke it', function () {
    consoleCustomer('basic');
    Livewire::test(Observability::class)
        ->call('generate')
        ->assertDispatched('error', fn (string $event, array $params) => $params[0] === 'Not included in your plan'
            && str_starts_with($params[1], 'Your plan does not include application traces.<br><a class="underline" href="'.route('subscription.show').'">'));

    $tenant = consoleCustomer('pro');
    $component = Livewire::test(Observability::class)->call('generate');
    $token = $component->get('newToken');
    expect(TenantTelemetry::tenantForToken($token)?->is($tenant))->toBeTrue();

    $component->call('revoke')
        ->assertDispatched('success', 'Token revoked.')
        ->assertSet('newToken', null);
    expect(TenantTelemetry::tenantForToken($token))->toBeNull();
});

it('sends help requests to the support address, and says when e-mail is not available', function () {
    consoleCustomer();

    Livewire::test(PlatformHelp::class)
        ->set('subject', 'Ajuda com o deploy')
        ->set('description', 'O deploy da minha aplicação falha na etapa de build.')
        ->call('submit')
        ->assertDispatched('error', 'Support is not available by e-mail yet. Please try again later.');

    config(['platform.support_email' => 'suporte@hostrema.com.br']);
    expect(PlatformHelp::supportEmail())->toBe('suporte@hostrema.com.br');
});

it('sends people outside the platform away from the integrations page', function () {
    config(['platform.enabled' => false]);
    consoleCustomer();

    Livewire::test(Integrations::class)->assertRedirect(route('dashboard'));
});

it('searches the logs of the resource given in the address', function () {
    consoleCustomer();

    Livewire::withQueryParams(['resource' => 'nao-existe'])
        ->test(Logs::class)
        ->assertSet('error', 'Resource not found.')
        ->assertSet('lines', []);
});

it('shows every refusal of a value the customer may not save as a notification', function () {
    consoleCustomer();
    $component = new class extends Component
    {
        public function save(): void
        {
            throw TenantInputRejected::withMessages([
                'image' => ['Privileged images are not allowed.'],
                'ports' => ['Port 22 is reserved.'],
            ]);
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    Livewire::test($component::class)
        ->call('save')
        ->assertDispatched('error', 'Not allowed on the platform', 'Privileged images are not allowed.<br>Port 22 is reserved.')
        ->assertHasErrors(['image', 'ports']);
});

it('shows each reason of a refusal on its own line, escaped, with the plans when the plan is what stops it', function () {
    consoleCustomer('basic');
    $component = new class extends \Livewire\Component
    {
        public string $reason = 'limit';

        public function save(): void
        {
            throw $this->reason === 'limit'
                ? new \App\Exceptions\PlatformPolicyViolation(['CPU above the plan.', 'Image <script>x</script> refused.'], 'Plan limit reached')
                : \App\Exceptions\PlatformPolicyViolation::single('Port 22 is reserved.', 'Not allowed');
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    Livewire::test($component::class)
        ->call('save')
        ->assertDispatched('error', fn (string $event, array $params) => $params[0] === 'Plan limit reached'
            && $params[1] === 'CPU above the plan.<br>Image &lt;script&gt;x&lt;/script&gt; refused.<br><a class="underline" href="'.route('subscription.show').'">See the plans and the usage of your account</a>')
        ->set('reason', 'rule')
        ->call('save')
        ->assertDispatched('error', 'Not allowed', 'Port 22 is reserved.');
});
