<?php

use App\Livewire\Platform\Admin;
use App\Livewire\Platform\Billing;
use App\Livewire\Platform\Onboarding;
use App\Livewire\Platform\Pricing;
use App\Livewire\Platform\UsageSummary;
use App\Models\InstanceSettings;
use App\Models\PlatformInvoice;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.plans.basic.stripe_price_monthly' => 'price_basic_m',
        'platform.plans.pro.stripe_price_monthly' => 'price_pro_m',
        'subscription.stripe_api_key' => null,
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0]);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

function uiTenantUser(string $plan = 'pro', string $status = PlatformTenant::STATUS_ACTIVE): User
{
    $user = User::factory()->create();
    $team = $user->teams()->first();
    PlatformTenant::forTeam($team)->update(['plan' => $plan, 'status' => $status]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

it('renders the plans with quotas and features', function () {
    uiTenantUser('basic');

    Livewire::test(Pricing::class)
        ->assertSee('Básico')
        ->assertSee('Profissional')
        ->assertSee('Enterprise')
        ->assertSee('Current plan')
        ->assertSee('Persistent volumes')
        ->call('setInterval', 'yearly')
        ->assertSet('interval', 'yearly');
});

it('renders plan and usage with invoices for tenants', function () {
    $user = uiTenantUser('pro');
    PlatformInvoice::create([
        'team_id' => $user->teams()->first()->id,
        'period_start' => now()->subMonth(),
        'period_end' => now(),
        'amount_cents' => 1234,
        'currency' => 'brl',
        'status' => PlatformInvoice::STATUS_PAID,
        'lines' => [],
    ]);

    Livewire::test(Billing::class)
        ->assertSee('Profissional')
        ->assertSee('Usage')
        ->assertSee('Invoice preview')
        ->assertSee('12,34');
    Livewire::test(UsageSummary::class)->assertSee('Profissional')->assertSee('CPU (reserved)');
});

it('shows the reason when a tenant is blocked', function () {
    uiTenantUser('pro', PlatformTenant::STATUS_SUSPENDED);

    Livewire::test(Billing::class)->assertSee('invoice is overdue');
});

it('onboards a tenant without asking for servers', function () {
    uiTenantUser('pro');
    PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true]);

    Livewire::test(Onboarding::class)
        ->assertSee('São Paulo')
        ->assertSee('First project name')
        ->assertDontSee('Private Key');
});

it('restricts the operator console to the root team', function () {
    uiTenantUser('pro');
    Livewire::test(Admin::class)->assertRedirect(route('dashboard'));
});
