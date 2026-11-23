<?php

use App\Console\Commands\PlatformInstall;
use App\Http\Controllers\PlatformOpenApiController;
use App\Livewire\Platform\Admin;
use App\Livewire\Platform\AdminBilling;
use App\Livewire\Platform\AdminPlans;
use App\Livewire\Platform\AdminSettings;
use App\Livewire\Platform\Billing;
use App\Models\AuditLog;
use App\Models\InstanceSettings;
use App\Models\PlatformInvoice;
use App\Models\PlatformPlan;
use App\Models\PlatformRegion;
use App\Models\PlatformSetting;
use App\Models\PlatformTenant;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\PlatformConfig;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('ssh-keys');
    PlatformSetting::flushMemo();
    PlatformConfig::flush();
    PlatformConfig::$enabledInTests = true;
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.locale' => 'en',
        'platform.billing.provider' => 'efi',
        'platform.metrics_token' => null,
        'platform.edge.token' => null,
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0]);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

afterEach(function () {
    PlatformConfig::$enabledInTests = false;
    PlatformConfig::$buildingConfigCache = false;
    PlatformConfig::flush();
});

function settingsOperator(string $role = 'owner'): User
{
    $user = User::factory()->create(['name' => 'Operator']);
    Team::find(0)->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => Team::find(0)]);

    return $user;
}

function settingsTenant(): PlatformTenant
{
    $user = User::factory()->create(['name' => 'Maria']);
    $team = $user->teams()->first();
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    PlatformTenant::forTeam($team)->update(['billing_profile' => [
        'name' => 'Maria Silva', 'document' => '52998224725', 'email' => 'maria@example.com', 'phone' => '(11) 98888-7777',
        'zip' => '01310-100', 'street' => 'Av. Paulista', 'number' => '1000', 'complement' => '', 'district' => 'Bela Vista',
        'city' => 'São Paulo', 'state' => 'SP',
    ]]);

    return PlatformTenant::forTeam($team)->fresh();
}

it('applies the saved settings over the configuration files and goes back to the default', function () {
    config(['platform.support_email' => 'env@example.com', 'platform.container.default_cpus' => 0.5]);
    PlatformConfig::set('platform.support_email', 'ajuda@example.com');
    PlatformConfig::set('platform.container.default_cpus', 0.75);
    PlatformPlan::query()->where('slug', 'pro')->first()->update(['quotas' => array_merge(PlanCatalog::get('pro')['quotas'], ['cpus' => 6.5])]);

    // Another request or job reads them again from the database, plans included.
    PlatformConfig::flush();
    config(['platform.support_email' => 'env@example.com', 'platform.container.default_cpus' => 0.5, 'platform.plans.pro.quotas.cpus' => 4]);
    PlatformConfig::apply();

    expect(config('platform.support_email'))->toBe('ajuda@example.com')
        ->and(config('platform.container.default_cpus'))->toBe(0.75)
        ->and(PlanCatalog::get('pro')['quotas']['cpus'])->toBe(6.5)
        ->and(PlatformConfig::isOverridden('platform.support_email'))->toBeTrue()
        ->and(PlatformSetting::query()->where('key', 'config:platform.support_email')->value('value'))->not->toContain('ajuda@example.com');

    PlatformConfig::reset('platform.support_email');
    expect(config('platform.support_email'))->toBe('env@example.com')
        ->and(PlatformConfig::isOverridden('platform.support_email'))->toBeFalse();

    // Saving the value of the configuration files removes the override instead of copying it.
    PlatformConfig::set('platform.container.default_cpus', 0.5);
    expect(PlatformConfig::isOverridden('platform.container.default_cpus'))->toBeFalse()
        ->and(config('platform.container.default_cpus'))->toBe(0.5);
});

it('normalizes what the owner types', function () {
    expect(PlatformConfig::normalize('platform.base_domain', 'https://Apps.Example.com/'))->toBe('apps.example.com')
        ->and(PlatformConfig::normalize('platform.reserved_hosts', 'www, API;www  status'))->toBe(['www', 'api', 'status'])
        ->and(PlatformConfig::normalize('platform.container.cap_add', 'chown, net_bind_service'))->toBe(['CHOWN', 'NET_BIND_SERVICE'])
        ->and(PlatformConfig::normalize('platform.security.operator_api_cidrs', '10.0.0.0/8, 2001:db8::/32 203.0.113.7'))->toBe(['10.0.0.0/8', '2001:db8::/32', '203.0.113.7'])
        ->and(PlatformConfig::normalize('platform.container.default_cpus', '0,75'))->toBe(0.75)
        ->and(PlatformConfig::normalize('platform.container.log_max_file', '5'))->toBe('5')
        ->and(PlatformConfig::normalize('platform.container.log_max_size', '20M'))->toBe('20m')
        ->and(PlatformConfig::normalize('platform.branding.enabled', '0'))->toBeFalse()
        ->and(PlatformConfig::normalize('platform.container.runtime', ''))->toBeNull();

    expect(fn () => PlatformConfig::normalize('platform.security.operator_api_cidrs', '10.0.0.0/33'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PlatformConfig::normalize('platform.container.cap_add', 'sys-admin'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PlatformConfig::set('platform.unknown', 'x'))->toThrow(InvalidArgumentException::class);
});

it('creates the internal tokens on the first start and keeps them', function () {
    PlatformConfig::apply();
    $metrics = config('platform.metrics_token');
    expect($metrics)->toHaveLength(48)
        ->and(config('platform.edge.token'))->toHaveLength(48);

    PlatformConfig::flush();
    config(['platform.metrics_token' => null]);
    PlatformConfig::apply();
    expect(config('platform.metrics_token'))->toBe($metrics);
});

it('never writes the saved values into the cached configuration', function () {
    config(['platform.support_email' => 'env@example.com']);
    PlatformConfig::set('platform.support_email', 'ajuda@example.com');
    PlatformConfig::flush();
    config(['platform.support_email' => 'env@example.com']);

    event(new CommandStarting('optimize', new ArrayInput([]), new NullOutput));
    expect(PlatformConfig::$buildingConfigCache)->toBeTrue();
    PlatformConfig::apply();
    expect(config('platform.support_email'))->toBe('env@example.com');

    event(new CommandFinished('optimize', new ArrayInput([]), new NullOutput, 0));
    expect(PlatformConfig::$buildingConfigCache)->toBeFalse();
    PlatformConfig::apply();
    expect(config('platform.support_email'))->toBe('ajuda@example.com');
});

it('applies the language chosen by the owner', function () {
    PlatformConfig::set('platform.locale', 'pt-br');
    PlatformConfig::flush();
    config(['platform.locale' => 'en']);
    PlatformConfig::apply();

    expect(app()->getLocale())->toBe('pt-br')
        ->and(__('Ready to sell?'))->toBe('Pronto para vender?');
});

it('lets the owner of the root team change the settings in the console', function () {
    settingsOperator('owner');

    Livewire::test(Admin::class, ['tab' => 'settings'])->assertSeeLivewire(AdminSettings::class);

    Livewire::test(AdminSettings::class)
        ->assertSee('Ready to sell?')
        ->assertSee('Platform name')
        ->set('form.platform__name', 'Nuvem Teste')
        ->set('form.platform__support_email', 'not-an-email')
        ->call('save')
        ->assertHasErrors(['form.platform__support_email'])
        ->set('form.platform__support_email', 'ajuda@example.com')
        ->set('form.platform__reserved_hosts', 'www, API')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertSee('custom');

    expect(config('platform.name'))->toBe('Nuvem Teste')
        ->and(config('platform.reserved_hosts'))->toBe(['www', 'api'])
        ->and(PlatformConfig::isOverridden('platform.name'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'platform.settings.updated')->latest('id')->first()->metadata['settings'])
        ->toContain('platform.name', 'platform.support_email', 'platform.reserved_hosts');

    Livewire::test(AdminSettings::class)
        ->call('resetSetting', 'platform__name')
        ->assertDispatched('success')
        ->assertSet('form.platform__name', 'Hostrema');
    expect(config('platform.name'))->toBe('Hostrema')
        ->and(PlatformConfig::isOverridden('platform.name'))->toBeFalse();
});

it('creates, edits, orders, hides and removes plans in the plans tab', function () {
    settingsOperator('owner');
    PlanCatalog::load();

    Livewire::test(AdminPlans::class)
        ->assertSee('Básico')
        ->call('edit', 'pro')
        ->assertSet('plan.price_monthly', '149,90')
        ->set('plan.price_monthly', '199,90')
        ->set('quotas.cpus', '8')
        ->set('unlimited.deployments_per_day', true)
        ->set('features.databases', false)
        ->call('addExtra')
        ->set('extras.0.text', 'Suporte prioritário')
        ->set('extras.0.help', 'Resposta em até 4 horas úteis.')
        ->call('save')
        ->assertHasNoErrors();

    expect(PlanCatalog::priceCents('pro', 'monthly'))->toBe(19990)
        ->and(PlanCatalog::get('pro')['quotas']['cpus'])->toEqual(8.0)
        ->and(PlanCatalog::get('pro')['quotas']['deployments_per_day'])->toBe(0)
        ->and(PlanCatalog::get('pro')['features']['databases'])->toBeFalse()
        ->and(PlanCatalog::extraLines(PlanCatalog::get('pro')))->toBe([['text' => 'Suporte prioritário', 'help' => 'Resposta em até 4 horas úteis.']]);

    // A new hidden plan, copied from another one and placed last.
    Livewire::test(AdminPlans::class)
        ->call('create', 'basic')
        ->set('plan.name', 'Startup Parceira')
        ->set('plan.price_monthly', '19,90')
        ->call('save')
        ->assertHasNoErrors();
    $startup = PlanCatalog::get('startup_parceira');
    expect($startup['public'])->toBeFalse()
        ->and($startup['quotas'])->toEqual(PlanCatalog::get('basic')['quotas'])
        ->and(array_key_last(PlanCatalog::all()))->toBe('startup_parceira')
        ->and(PlanCatalog::isSelfServe('startup_parceira'))->toBeFalse()
        ->and(PlanCatalog::public())->not->toHaveKey('startup_parceira');

    Livewire::test(AdminPlans::class)->call('move', 'startup_parceira', -1);
    expect(array_keys(PlanCatalog::all()))->toBe(['basic', 'pro', 'startup_parceira', 'enterprise']);

    // Removing a plan moves its customers.
    $tenant = settingsTenant();
    $tenant->update(['plan' => 'startup_parceira']);
    settingsOperator('owner');
    Livewire::test(AdminPlans::class)
        ->call('confirmDelete', 'startup_parceira')
        ->set('replacement', 'startup_parceira')
        ->call('delete')
        ->assertHasErrors(['replacement'])
        ->set('replacement', 'basic')
        ->call('delete')
        ->assertHasNoErrors();
    expect(PlanCatalog::exists('startup_parceira'))->toBeFalse()
        ->and($tenant->fresh()->plan)->toBe('basic');
});

it('lets only the owner change the plans', function () {
    settingsOperator('admin');

    Livewire::test(AdminPlans::class)
        ->assertSee('Profissional')
        ->assertDontSee('New plan')
        ->call('edit', 'pro')
        ->assertDispatched('error')
        ->assertSet('editing', null);
});

it('gives a customer limits, capabilities, free days and a courtesy account of its own', function () {
    $tenant = settingsTenant();
    $tenant->update(['plan' => null, 'status' => PlatformTenant::STATUS_PENDING]);
    settingsOperator('owner');

    Livewire::test(Admin::class)
        ->call('editTenant', $tenant->id)
        ->set('editingPlan', 'basic')
        ->set('editingQuotas.cpus', '1.5')
        ->set('editingFeatures.public_databases', '1')
        ->set('editingFeatures.databases', '0')
        ->set('editingPeriodEnd', now()->addDays(45)->format('Y-m-d'))
        ->set('editingExempt', true)
        ->set('editingNotes', 'Parceiro de lançamento')
        ->call('saveTenant')
        ->assertHasNoErrors();

    $tenant = $tenant->fresh();
    expect($tenant->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->quota('cpus'))->toBe(1.5)
        ->and($tenant->hasFeature('public_databases'))->toBeTrue()
        ->and($tenant->hasFeature('databases'))->toBeFalse()
        ->and($tenant->billing_exempt)->toBeTrue()
        ->and($tenant->current_period_end->toDateString())->toBe(now()->addDays(45)->toDateString())
        ->and(\App\Models\Subscription::query()->where('team_id', $tenant->team_id)->value('stripe_invoice_paid'))->toBeTruthy()
        ->and($tenant->toArray())->not->toHaveKey('operator_notes');

    // A courtesy account renews without an invoice.
    $tenant->update(['current_period_end' => now()->subMinute()]);
    PlatformBilling::renew($tenant->fresh());
    expect(PlatformInvoice::query()->where('team_id', $tenant->team_id)->count())->toBe(0)
        ->and($tenant->fresh()->current_period_end->isFuture())->toBeTrue();
});

it('lets an operator access the dashboard as a customer and come back, recording it', function () {
    $tenant = settingsTenant();
    $customer = $tenant->team->members()->first();
    $operator = settingsOperator('owner');

    Livewire::test(Admin::class)
        ->call('impersonate', $tenant->id, $customer->id)
        ->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($customer->id)
        ->and(session(\App\Services\Platform\Impersonation::SESSION_KEY))->toBe($operator->id)
        ->and(AuditLog::query()->where('action', 'platform.impersonation.started')->value('metadata'))->toMatchArray(['operator_id' => $operator->id]);

    // What is done meanwhile is recorded with the operator.
    audit_log('platform.test.action', null, [], $tenant->team_id);
    expect(AuditLog::query()->where('action', 'platform.test.action')->first())
        ->actor_type->toBe('operator')
        ->metadata->toMatchArray(['impersonated_by' => $operator->id]);

    $this->post(route('platform.impersonation.stop'))->assertRedirect(route('admin.platform', ['tab' => 'tenants']));
    expect(auth()->id())->toBe($operator->id)
        ->and(session()->has(\App\Services\Platform\Impersonation::SESSION_KEY))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'platform.impersonation.ended')->exists())->toBeTrue();

    // Operators can not be accessed this way.
    $other = settingsOperator('admin');
    expect(\App\Services\Platform\Impersonation::canImpersonate($operator, $other))->toBeFalse();
});

it('checks values that depend on each other', function () {
    settingsOperator('owner');

    Livewire::test(AdminSettings::class)
        ->call('selectGroup', 'containers')
        ->set('form.platform__container__public_port_min', '30000')
        ->set('form.platform__container__public_port_max', '20000')
        ->call('save')
        ->assertHasErrors(['form.platform__container__public_port_max']);

    expect(PlatformConfig::isOverridden('platform.container.public_port_min'))->toBeFalse();
});

it('keeps secrets masked, saves new ones and generates internal tokens', function () {
    settingsOperator('owner');

    $component = Livewire::test(AdminSettings::class)
        ->call('selectGroup', 'internal')
        ->assertSee('Not configured')
        ->call('generateSecret', 'platform__metrics_token')
        ->assertSet('revealed', 'platform__metrics_token');
    $token = config('platform.metrics_token');
    expect($token)->toHaveLength(48);
    $component->assertSee($token)->call('reveal', 'platform__metrics_token')->assertDontSee($token);

    Livewire::test(AdminSettings::class)
        ->call('selectGroup', 'billing')
        ->set('secrets.subscription__stripe_api_key', 'short')
        ->call('saveSecret', 'subscription__stripe_api_key')
        ->assertHasErrors(['secrets.subscription__stripe_api_key'])
        ->set('secrets.subscription__stripe_api_key', 'sk_test_1234567890abcdef')
        ->call('saveSecret', 'subscription__stripe_api_key')
        ->assertHasNoErrors()
        ->assertDontSee('sk_test_1234567890abcdef');

    expect(config('subscription.stripe_api_key'))->toBe('sk_test_1234567890abcdef')
        ->and(AuditLog::query()->pluck('metadata')->toJson())->not->toContain('sk_test_1234567890abcdef');
});

it('shows the settings read only to admins of the root team', function () {
    settingsOperator('admin');

    Livewire::test(AdminSettings::class)
        ->assertSee('Read only')
        ->set('form.platform__name', 'Outro nome')
        ->call('save')
        ->assertDispatched('error')
        ->call('generateSecret', 'platform__metrics_token')
        ->call('resetSetting', 'platform__name');

    expect(config('platform.name'))->toBe('Hostrema')
        ->and(PlatformConfig::isOverridden('platform.name'))->toBeFalse()
        ->and(config('platform.metrics_token'))->toBeNull();
});

it('keeps customers out of the settings', function () {
    $this->withoutVite();
    settingsTenant();

    Livewire::test(AdminSettings::class)->assertForbidden();
});

it('lets the owner fix the name and the log address of a region', function () {
    settingsOperator('owner');
    $region = PlatformRegion::query()->create(['slug' => 'sa-east-1', 'name' => 'sa-east-1', 'is_active' => true]);

    Livewire::test(Admin::class, ['tab' => 'nodes'])
        ->call('editRegion', $region->id)
        ->set('editingRegionName', 'São Paulo')
        ->set('editingRegionLogsUrl', 'http://10.20.0.10:3100/')
        ->call('saveRegion')
        ->assertHasNoErrors();

    expect($region->fresh()->name)->toBe('São Paulo')
        ->and($region->fresh()->logs_url)->toBe('http://10.20.0.10:3100');
});

it('issues manual invoices with instructions and activates the plan when the payment is simulated', function () {
    Http::fake();
    PlatformConfig::set('platform.billing.provider', 'manual');
    PlatformConfig::set('platform.billing.allow_simulated_payments', true);
    PlatformConfig::set('platform.billing.manual_instructions', 'Pix: financeiro@example.com');
    $tenant = settingsTenant();

    $invoice = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];

    expect($invoice->status)->toBe(PlatformInvoice::STATUS_OPEN)
        ->and(PlatformBilling::isManualInvoice($invoice))->toBeTrue()
        ->and($invoice->payment_details['instructions'])->toBe('Pix: financeiro@example.com')
        ->and(PlatformBilling::reconcileOpen())->toBe(0);
    Http::assertNothingSent();

    Livewire::test(Billing::class)
        ->assertSee('How to pay')
        ->assertSee('Pix: financeiro@example.com')
        ->assertSee('Simulate payment (tests)')
        ->call('simulatePayment', $invoice->uuid)
        ->assertDispatched('success');

    $tenant->refresh();
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_PAID)
        ->and($invoice->fresh()->payment_method)->toBe('SIMULATED')
        ->and($tenant->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->plan)->toBe('pro')
        ->and(Subscription::query()->where('team_id', $tenant->team_id)->value('stripe_invoice_paid'))->toBeTruthy();
});

it('only lets customers simulate payments when the owner allows it', function () {
    PlatformConfig::set('platform.billing.provider', 'manual');
    $tenant = settingsTenant();
    $invoice = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];

    Livewire::test(Billing::class)
        ->assertSee('How to pay')
        ->assertDontSee('Simulate payment (tests)')
        ->call('simulatePayment', $invoice->uuid);
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_OPEN);

    // The operator confirms the transfer.
    settingsOperator('owner');
    Livewire::test(AdminBilling::class)->call('markPaid', $invoice->uuid);
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_PAID)
        ->and($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_ACTIVE);
});

it('prepares a new installation to sell and keeps the choices of the owner on the next run', function () {
    $this->artisan('platform:install', ['--email' => 'Dono@Example.com', '--password' => 'segredo-forte-123', '--url' => 'https://painel.example.com/'])
        ->assertSuccessful();

    $owner = User::query()->find(0);
    expect($owner->email)->toBe('dono@example.com')
        ->and(Hash::check('segredo-forte-123', $owner->password))->toBeTrue()
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and(Team::find(0)->members()->whereKey(0)->first()->pivot->role)->toBe('owner');

    $settings = InstanceSettings::query()->find(0);
    expect($settings->is_registration_enabled)->toBeTrue()
        ->and($settings->is_api_enabled)->toBeTrue()
        ->and($settings->fqdn)->toBe('https://painel.example.com')
        ->and(PlatformRegion::query()->where('slug', 'sa-east-1')->where('is_default', true)->exists())->toBeTrue()
        ->and(config('platform.container.runtime'))->toBe('runsc')
        ->and(config('platform.metrics_token'))->toHaveLength(48);

    // The owner chooses runc in the panel: running the command again must not undo it.
    PlatformConfig::reset('platform.container.runtime');
    $this->artisan('platform:install', ['--password' => 'outra-senha-456'])->assertSuccessful();
    expect(config('platform.container.runtime'))->toBeNull()
        ->and(Hash::check('segredo-forte-123', User::query()->find(0)->password))->toBeTrue()
        ->and(User::query()->count())->toBe(1);

    $this->artisan('platform:install', ['--password' => 'outra-senha-456', '--reset-password' => true])->assertSuccessful();
    expect(Hash::check('outra-senha-456', User::query()->find(0)->password))->toBeTrue();
});

it('refuses to install while the platform is off or without an owner e-mail', function () {
    $this->artisan('platform:install', ['--no-interaction' => true])->assertFailed();

    config(['platform.enabled' => false]);
    $this->artisan('platform:install', ['--email' => 'dono@example.com'])->assertFailed();
    expect(User::query()->find(0))->toBeNull();
});

it('configures settings, e-mail, Efí Bank, the node key and an Ansible token without the panel', function () {
    $this->artisan('platform:install', ['--email' => 'owner@hostrema.com.br', '--password' => 'owner-pass-1234', '--url' => 'https://painel.hostrema.com.br', '--no-interaction' => true])->assertSuccessful();
    Http::fake([
        'https://pix-h.api.efipay.com.br/oauth/token' => Http::response(['access_token' => 'pix-token', 'expires_in' => 3600]),
        'https://pix-h.api.efipay.com.br/v2/webhook/*' => Http::response(['webhookUrl' => 'x']),
        'https://cobrancas-h.api.efipay.com.br/v1/authorize' => Http::response(['access_token' => 'charges-token', 'expires_in' => 600]),
    ]);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'efi'], $key), null, $key, 2);
    openssl_pkcs12_export($certificate, $p12, $key, '');
    $file = tempnam(sys_get_temp_dir(), 'configure');
    file_put_contents($file, json_encode([
        'settings' => ['platform.base_domain' => 'apps.hostrema.com.br', 'platform.edge.proxy_protocol' => true, 'platform.edge.token' => str_repeat('e', 64)],
        'smtp' => ['host' => 'email-smtp.sa-east-1.amazonaws.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'AKIAEXAMPLE', 'password' => 'smtp-secret', 'from_address' => 'nao-responda@hostrema.com.br', 'from_name' => 'Hostrema'],
        'efi' => ['environment' => 'sandbox', 'client_id' => 'Client_Id_x', 'client_secret' => 'Client_Secret_x', 'pix_key' => 'pix@hostrema.com.br', 'certificate_base64' => base64_encode($p12)],
        'node_key' => true,
        'api_token' => 'ansible',
    ]));

    expect(\Illuminate\Support\Facades\Artisan::call('platform:configure', ['--file' => $file, '--json' => true]))->toBe(0);
    $output = json_decode(trim(\Illuminate\Support\Facades\Artisan::output()), true);
    unlink($file);

    $settings = InstanceSettings::query()->find(0);
    PlatformSetting::flushMemo();
    expect($output['errors'])->toBe([])
        ->and(config('platform.base_domain'))->toBe('apps.hostrema.com.br')
        ->and(config('platform.edge.proxy_protocol'))->toBeTrue()
        ->and($settings->smtp_enabled)->toBeTrue()
        ->and($settings->smtp_host)->toBe('email-smtp.sa-east-1.amazonaws.com')
        ->and($settings->smtp_encryption)->toBe('starttls')
        ->and($settings->smtp_password)->toBe('smtp-secret')
        ->and($output['efi'])->toMatchArray(['configured' => true, 'pix' => true, 'registered' => ['pix', 'boleto']])
        ->and(\App\Services\Platform\Efi\EfiSettings::value('client_secret'))->toBe('Client_Secret_x')
        ->and($output['node_key']['uuid'])->toBe(PrivateKey::query()->where('name', 'Hostrema nodes')->value('uuid'))
        ->and($output['node_key']['public_key'])->toStartWith('ssh-ed25519 ');

    // The token reads the API as the operator; a second run replaces it and keeps the same key.
    $this->getJson('/api/v1/servers', ['Authorization' => 'Bearer '.$output['api_token']])->assertOk();
    expect(\Illuminate\Support\Facades\Artisan::call('platform:configure', ['--node-key' => true, '--api-token' => 'ansible', '--json' => true]))->toBe(0);
    $again = json_decode(trim(\Illuminate\Support\Facades\Artisan::output()), true);
    expect($again['node_key']['uuid'])->toBe($output['node_key']['uuid'])
        ->and(\Laravel\Sanctum\PersonalAccessToken::query()->where('name', 'ansible')->count())->toBe(1)
        ->and((int) \Laravel\Sanctum\PersonalAccessToken::query()->where('name', 'ansible')->value('team_id'))->toBe(0);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/servers', ['Authorization' => 'Bearer '.$output['api_token']])->assertUnauthorized();
});

it('refuses unknown or invalid settings and says why', function () {
    $this->artisan('platform:configure', ['--set' => ['platform.nope=1', 'platform.support_email=not-an-email', 'platform.base_domain=apps.example.com']])
        ->expectsOutputToContain('Unknown setting platform.nope.')
        ->assertFailed();

    expect(config('platform.base_domain'))->toBe('apps.example.com')
        ->and(PlatformConfig::isOverridden('platform.support_email'))->toBeFalse();
});

it('builds the local test environment with manual payments, Mailpit and a demo customer', function () {
    $this->artisan('platform:install', [
        '--local' => true, '--skip-node' => true, '--password' => 'owner-1234',
        '--demo' => true, '--demo-password' => 'demo-1234',
    ])->assertSuccessful();

    expect(User::query()->find(0)->email)->toBe('owner@hostrema.local')
        ->and(PlatformBilling::isManual())->toBeTrue()
        ->and(PlatformBilling::allowsSimulatedPayments())->toBeTrue()
        ->and(config('platform.security.require_two_factor'))->toBe('none')
        ->and(config('platform.base_domain'))->toBe('localhost')
        ->and(config('platform.container.tenant_slices'))->toBeFalse()
        // Like production, a stack of several containers fits the smallest plan.
        ->and(config('platform.container.shared_plan'))->toBeTrue()
        ->and(config('platform.container.runtime'))->toBeNull();

    $settings = InstanceSettings::query()->find(0);
    expect($settings->smtp_enabled)->toBeTrue()
        ->and($settings->smtp_host)->toBe('mailpit')
        ->and($settings->smtp_port)->toBe(1025)
        ->and($settings->is_dns_validation_enabled)->toBeFalse()
        ->and($settings->fqdn)->toBeNull()
        ->and(PlatformRegion::query()->where('slug', 'local')->where('is_default', true)->exists())->toBeTrue();

    $demo = User::query()->where('email', 'demo@hostrema.local')->first();
    $tenant = PlatformTenant::forTeam($demo->teams()->first());
    expect(Hash::check('demo-1234', $demo->password))->toBeTrue()
        ->and($demo->email_verified_at)->not->toBeNull()
        ->and($tenant->plan)->toBe('pro')
        ->and($tenant->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->provisioned_at)->not->toBeNull()
        ->and(Subscription::query()->where('team_id', $tenant->team_id)->value('stripe_invoice_paid'))->toBeTruthy();
});

it('gives the local node a key of its own and survives a node that is not up yet', function () {
    config(['constants.ssh.max_retries' => 1, 'constants.ssh.retry_base_delay' => 0, 'constants.ssh.mux_enabled' => false]);
    Process::fake(['*' => Process::result(errorOutput: 'ssh: Could not resolve hostname hostrema-node', exitCode: 255)]);

    $this->artisan('platform:install', ['--node-public-key' => true])
        ->expectsOutputToContain('ssh-ed25519 ')
        ->assertSuccessful();
    $this->artisan('platform:install', ['--node-public-key' => true])->assertSuccessful();
    expect(PrivateKey::query()->where('team_id', 0)->where('name', PlatformInstall::LOCAL_NODE_KEY)->count())->toBe(1);

    $this->artisan('platform:install', ['--local' => true, '--password' => 'owner-1234'])
        ->expectsOutputToContain('The local node is not ready')
        ->assertSuccessful();

    $node = Server::query()->where('team_id', 0)->where('ip', 'hostrema-node')->first();
    expect($node)->not->toBeNull()
        ->and($node->platform_region_id)->toBe(PlatformRegion::query()->where('slug', 'local')->value('id'))
        ->and($node->settings->wildcard_domain)->toBe('http://localhost')
        ->and($node->private_key_id)->toBe(PrivateKey::query()->where('name', PlatformInstall::LOCAL_NODE_KEY)->value('id'));
});

/**
 * Sources copied into the image by a Dockerfile, as "source => destination".
 *
 * @return array<string, string>
 */
function copiedByDockerfile(string $dockerfile): array
{
    preg_match_all('#^COPY --chown=www-data:www-data (\S+) (\S+)$#m', (string) file_get_contents(base_path($dockerfile)), $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn ($match) => [rtrim($match[1], '/') => $match[2]])->all();
}

it('ships the files the platform reads at runtime in the production image', function () {
    $copied = array_keys(copiedByDockerfile('docker/platform/Dockerfile'));

    foreach ([PlatformOpenApiController::path(), lang_path('platform/pt-br.json'), base_path('routes/platform/api.php'), resource_path('views/livewire/platform/admin-settings.blade.php'), base_path('templates/service-templates-latest.json')] as $file) {
        $relative = ltrim(str_replace(base_path(), '', $file), '/');
        $shipped = collect($copied)->contains(fn ($path) => $relative === $path || str_starts_with($relative, $path.'/'));
        expect($shipped)->toBeTrue("{$relative} is not copied into the production image");
    }
});

it('copies everything the official image copies, so upstream additions are not lost', function () {
    $official = copiedByDockerfile('docker/production/Dockerfile');

    expect($official)->not->toBeEmpty()
        ->and(array_diff_assoc($official, copiedByDockerfile('docker/platform/Dockerfile')))->toBe([]);
});

it('has a Portuguese translation for every text of the platform screens', function () {
    $translations = json_decode((string) file_get_contents(lang_path('platform/pt-br.json')), true);
    $files = collect([
        resource_path('views/livewire/platform'), resource_path('views/platform'), app_path('Livewire/Platform'), app_path('Services/Platform'), app_path('Actions/Platform'),
    ])->flatMap(fn (string $directory) => \Illuminate\Support\Facades\File::allFiles($directory))
        ->map(fn ($file) => $file->getPathname())
        ->push(resource_path('views/components/platform-banner.blade.php'), app_path('Exceptions/PlatformPolicyViolation.php'), app_path('Models/PlatformTenant.php'),
            app_path('Models/PrivacyConsent.php'), app_path('Models/PrivacyRequest.php'), app_path('Models/PrivacyDeletedAccount.php'));

    $missing = [];
    // Names and explanations of the limits and capabilities of the plans.
    foreach (\App\Services\Platform\PlanCatalog::QUOTAS as $key => $definition) {
        foreach (['title', 'label', 'help'] as $field) {
            if (! array_key_exists($definition[$field], $translations)) {
                $missing[] = "PlanCatalog::QUOTAS.{$key}.{$field}";
            }
        }
    }
    foreach (\App\Services\Platform\PlanCatalog::FEATURES as $key => $definition) {
        foreach (['label', 'help'] as $field) {
            if (! array_key_exists($definition[$field], $translations)) {
                $missing[] = "PlanCatalog::FEATURES.{$key}.{$field}";
            }
        }
    }
    // Groups, labels, explanations and options of the settings tab.
    foreach (\App\Services\Platform\PlatformConfig::GROUPS as $key => $label) {
        if (! array_key_exists($label, $translations)) {
            $missing[] = "PlatformConfig::GROUPS.{$key}";
        }
    }
    foreach (\App\Services\Platform\PlatformConfig::definitions() as $path => $definition) {
        $texts = array_filter([$definition['label'] ?? null, $definition['help'] ?? null, ...array_values((array) ($definition['options'] ?? []))], 'is_string');
        foreach ($texts as $text) {
            if (! array_key_exists($text, $translations)) {
                $missing[] = "PlatformConfig::definitions.{$path}: {$text}";
            }
        }
    }
    // Plan descriptions are translated where they are shown.
    foreach ((array) config('platform.plans') as $id => $plan) {
        $description = (string) data_get($plan, 'description');
        if ($description !== '' && ! array_key_exists($description, $translations)) {
            $missing[] = "config/platform.php: plans.{$id}.description";
        }
    }
    foreach ($files as $file) {
        preg_match_all("/(?:__|trans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);
        foreach ($matches[1] as $text) {
            $text = stripslashes($text);
            if (! array_key_exists($text, $translations)) {
                $missing[] = str_replace(base_path().'/', '', $file).': '.$text;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('compiles every platform screen into valid PHP, with no directive left as text', function () {
    $views = collect([resource_path('views/livewire/platform'), resource_path('views/platform')])
        ->flatMap(fn (string $directory) => \Illuminate\Support\Facades\File::allFiles($directory))
        ->map(fn ($file) => $file->getPathname())
        ->push(resource_path('views/components/platform-banner.blade.php'), resource_path('views/emails/platform-notice.blade.php'));

    $broken = [];
    foreach ($views as $view) {
        $compiled = \Illuminate\Support\Facades\Blade::compileString((string) file_get_contents($view));
        // A directive right after a letter ("painel@endauth") is left as text, and the block it
        // should close ends somewhere else.
        if (preg_match('/@(?:end)?(?:if|elseif|else|unless|auth|guest|can|cannot|error|isset|empty|foreach|forelse|php)\b/', $compiled, $match)) {
            $broken[] = str_replace(resource_path(), '', $view).": {$match[0]}";

            continue;
        }
        try {
            token_get_all($compiled, TOKEN_PARSE);
        } catch (\ParseError $error) {
            $broken[] = str_replace(resource_path(), '', $view).": {$error->getMessage()}";
        }
    }

    expect($broken)->toBe([]);
});

it('never lets an operator access as itself, another operator or while already accessing, and ends a lost access', function () {
    $operator = User::factory()->create();
    Team::find(0)->members()->attach($operator->id, ['role' => 'owner']);
    $customer = User::factory()->create();
    $colleague = User::factory()->create();
    Team::find(0)->members()->attach($colleague->id, ['role' => 'admin']);
    $this->actingAs($operator);
    session(['currentTeam' => Team::find(0)]);

    expect(\App\Services\Platform\Impersonation::canImpersonate($operator, $operator))->toBeFalse()
        ->and(\App\Services\Platform\Impersonation::canImpersonate(null, $customer))->toBeFalse()
        ->and(\App\Services\Platform\Impersonation::canImpersonate($operator, $colleague))->toBeFalse()
        ->and(\App\Services\Platform\Impersonation::canImpersonate($operator, $customer))->toBeTrue();
    session([\App\Services\Platform\Impersonation::SESSION_KEY => $operator->id]);
    expect(\App\Services\Platform\Impersonation::canImpersonate($operator, $customer))->toBeFalse();
    config(['platform.enabled' => false]);
    session()->forget(\App\Services\Platform\Impersonation::SESSION_KEY);
    expect(\App\Services\Platform\Impersonation::canImpersonate($operator, $customer))->toBeFalse();
    config(['platform.enabled' => true]);

    // Nothing to end without an access; an access whose operator is gone logs out.
    $this->actingAs($customer);
    \App\Services\Platform\Impersonation::stop();
    expect(auth()->id())->toBe($customer->id);
    session([\App\Services\Platform\Impersonation::SESSION_KEY => 987654]);
    \App\Services\Platform\Impersonation::stop();
    expect(auth()->check())->toBeFalse()
        ->and(session()->has(\App\Services\Platform\Impersonation::SESSION_KEY))->toBeFalse();
});
