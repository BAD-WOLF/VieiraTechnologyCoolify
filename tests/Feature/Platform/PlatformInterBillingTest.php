<?php

use App\Actions\Platform\ProvisionTenant;
use App\Jobs\PlatformInterSyncInvoiceJob;
use App\Jobs\PlatformNoticeJob;
use App\Livewire\Platform\AdminBilling;
use App\Livewire\Platform\Pricing;
use App\Models\InstanceSettings;
use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Models\PlatformTenant;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\BillingProfile;
use App\Services\Platform\Inter\InterClient;
use App\Services\Platform\Inter\InterException;
use App\Services\Platform\Inter\InterSettings;
use App\Services\Platform\InterBilling;
use App\Services\Platform\PlatformBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const INTER = 'https://cdpj-sandbox.partners.uatinter.co';

beforeEach(function () {
    Queue::fake();
    $GLOBALS['interFaked'] = false;
    PlatformSetting::flushMemo();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.billing.provider' => 'inter',
        'platform.inter.environment' => 'sandbox',
        'platform.inter.client_id' => 'client-id',
        'platform.inter.client_secret' => 'client-secret',
        'platform.inter.certificate' => "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----",
        'platform.inter.private_key' => "-----BEGIN PRIVATE KEY-----\nMIIBfake\n-----END PRIVATE KEY-----",
        'platform.inter.webhook_token' => 'webhooktoken1234567890abcdef',
        'platform.plans.basic.price_monthly' => '49.90',
        'platform.plans.pro.price_monthly' => '149.90',
        'platform.plans.pro.price_yearly' => '1499.00',
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.com.br']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

function interFake(array $charge = []): void
{
    $GLOBALS['interCharge'] = $charge;
    if ($GLOBALS['interFaked'] ?? false) {
        return;
    }
    $GLOBALS['interFaked'] = true;
    Http::fake([
        INTER.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        INTER.'/cobranca/v3/cobrancas' => Http::response(['codigoSolicitacao' => 'cod-1']),
        INTER.'/cobranca/v3/cobrancas/webhook' => Http::response(null, 204),
        INTER.'/cobranca/v3/cobrancas/*' => fn () => Http::response(array_replace_recursive([
            'cobranca' => ['codigoSolicitacao' => 'cod-1', 'situacao' => 'A_RECEBER'],
            'boleto' => ['linhaDigitavel' => '07790001161234567890123456789012345678901234', 'nossoNumero' => '123'],
            'pix' => ['txid' => 'tx1', 'pixCopiaECola' => '00020101021226990014br.gov.bcb.pix2577pix-qrcode.inter.co'],
        ], $GLOBALS['interCharge'])),
    ]);
}

function interTenant(?string $plan = null, string $status = PlatformTenant::STATUS_PENDING): PlatformTenant
{
    $user = User::factory()->create();
    $team = $user->teams()->first();
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(['plan' => $plan, 'status' => $status, 'billing_profile' => [
        'name' => 'Maria Silva', 'document' => '52998224725', 'email' => 'maria@example.com', 'phone' => '(11) 98888-7777',
        'zip' => '01310-100', 'street' => 'Av. Paulista', 'number' => '1000', 'complement' => '', 'district' => 'Bela Vista',
        'city' => 'São Paulo', 'state' => 'SP',
    ]]);

    return $tenant->fresh();
}

it('parses prices and validates the payer documents', function () {
    expect(InterBilling::toCents('49.90'))->toBe(4990)
        ->and(InterBilling::toCents('49,90'))->toBe(4990)
        ->and(InterBilling::toCents('1.499,00'))->toBe(149900)
        ->and(InterBilling::toCents('1,499.00'))->toBe(149900)
        ->and(InterBilling::toCents(''))->toBeNull()
        ->and(InterBilling::toCents('0'))->toBeNull()
        ->and(BillingProfile::validDocument('529.982.247-25'))->toBeTrue()
        ->and(BillingProfile::validDocument('11.222.333/0001-81'))->toBeTrue()
        ->and(BillingProfile::validDocument('111.111.111-11'))->toBeFalse()
        ->and(BillingProfile::validDocument('12345678900'))->toBeFalse();

    $payer = BillingProfile::payer(interTenant());
    expect($payer)->toMatchArray([
        'cpfCnpj' => '52998224725', 'tipoPessoa' => 'FISICA', 'cep' => '01310100', 'uf' => 'SP', 'ddd' => '11', 'telefone' => '988887777',
    ])->not->toHaveKey('complemento');
});

it('reads the PEM credentials from text, base64 or files and writes them for mTLS', function () {
    $pem = "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----";
    config(['platform.inter.certificate' => base64_encode($pem)]);
    expect(InterSettings::pem('certificate'))->toBe($pem."\n");

    $path = InterSettings::certificatePath();
    expect(file_get_contents($path))->toBe($pem."\n")
        ->and(substr(sprintf('%o', fileperms($path)), -3))->toBe('600');

    config(['platform.inter.certificate' => 'not a certificate']);
    expect(InterSettings::pem('certificate'))->toBeNull()->and(InterSettings::isConfigured())->toBeFalse();
});

it('charges the first period with boleto + Pix and activates the plan when paid', function () {
    interFake();
    $tenant = interTenant();

    $result = InterBilling::subscribe($tenant, 'pro', 'monthly');
    $invoice = $result['invoice'];
    expect($result['action'])->toBe('checkout')
        ->and($invoice->amount_cents)->toBe(14990)
        ->and($invoice->provider_reference)->toBe('cod-1')
        ->and($invoice->number)->toStartWith('HR')
        ->and($tenant->fresh()->plan)->toBeNull();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST' && $request->url() === INTER.'/cobranca/v3/cobrancas'
            && $request['valorNominal'] == 149.9
            && $request['pagador']['cpfCnpj'] === '52998224725'
            && $request['pagador']['tipoPessoa'] === 'FISICA'
            && str_starts_with($request['seuNumero'], 'HR')
            && $request['multa']['codigo'] === 'PERCENTUAL'
            && $request->hasHeader('Authorization', 'Bearer tok');
    });
    Queue::assertPushed(PlatformInterSyncInvoiceJob::class);

    $invoice = InterBilling::sync($invoice);
    expect(data_get($invoice->payment_details, 'pix_copia_e_cola'))->toStartWith('000201')
        ->and($invoice->status)->toBe(PlatformInvoice::STATUS_OPEN);
    Queue::assertPushed(PlatformNoticeJob::class);

    $this->postJson('/api/v1/billing/webhooks/inter/wrong-token-0000000000000', [['codigoSolicitacao' => 'cod-1']])->assertNotFound();
    $this->postJson('/api/v1/billing/webhooks/inter/webhooktoken1234567890abcdef', [['codigoSolicitacao' => 'cod-1', 'situacao' => 'RECEBIDO']])
        ->assertOk()->assertJson(['received' => 1]);

    interFake(['cobranca' => ['situacao' => 'RECEBIDO', 'valorTotalRecebido' => 149.9, 'origemRecebimento' => 'PIX']]);
    (new PlatformInterSyncInvoiceJob($invoice->id))->handle();

    $invoice->refresh();
    $tenant->refresh();
    expect($invoice->status)->toBe(PlatformInvoice::STATUS_PAID)
        ->and($invoice->payment_method)->toBe('PIX')
        ->and($invoice->amount_paid_cents)->toBe(14990)
        ->and($tenant->plan)->toBe('pro')
        ->and($tenant->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->billing_provider)->toBe('inter')
        ->and($tenant->current_period_end->isFuture())->toBeTrue()
        ->and(Subscription::query()->where('team_id', $tenant->team_id)->value('stripe_invoice_paid'))->toBeTrue();
    Queue::assertPushed(fn (\Lorisleiva\Actions\Decorators\JobDecorator $job) => $job->getAction() instanceof ProvisionTenant);
});

it('renews at the end of the period, marks overdue invoices and never duplicates them', function () {
    interFake();
    $tenant = interTenant('pro', PlatformTenant::STATUS_ACTIVE);
    $tenant->update(['billing_provider' => 'inter', 'billing_interval' => 'monthly', 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subMinute()]);

    InterBilling::runCycle();
    InterBilling::runCycle();

    $renewals = PlatformInvoice::query()->where('team_id', $tenant->team_id)->where('type', 'subscription')->get();
    expect($renewals)->toHaveCount(1)
        ->and($renewals->first()->period_start->equalTo($tenant->current_period_end))->toBeTrue()
        ->and($renewals->first()->amount_cents)->toBe(14990)
        ->and($renewals->first()->provider_reference)->toBe('cod-1');

    $renewals->first()->update(['due_date' => now()->subDays(2)->toDateString()]);
    InterBilling::markOverdue();
    expect($tenant->fresh()->past_due_since)->not->toBeNull();

    InterBilling::markPaid($renewals->first()->fresh(), 14990, 'BOLETO');
    $tenant->refresh();
    expect($tenant->past_due_since)->toBeNull()
        ->and($tenant->current_period_start->equalTo($renewals->first()->period_start))->toBeTrue()
        ->and($tenant->current_period_end->isFuture())->toBeTrue();
});

it('ends the subscription at the end of the period when cancelled', function () {
    interFake();
    $tenant = interTenant('pro', PlatformTenant::STATUS_ACTIVE);
    $tenant->update(['billing_provider' => 'inter', 'billing_interval' => 'monthly', 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->addDay()]);
    InterBilling::cancelAtPeriodEnd($tenant->fresh());
    expect(Subscription::query()->where('team_id', $tenant->team_id)->value('stripe_cancel_at_period_end'))->toBeTrue();

    $tenant->update(['current_period_end' => now()->subMinute()]);
    InterBilling::renewDue();

    $tenant->refresh();
    expect($tenant->status)->toBe(PlatformTenant::STATUS_CANCELLED)
        ->and($tenant->suspended_at)->not->toBeNull()
        ->and(PlatformInvoice::query()->where('team_id', $tenant->team_id)->count())->toBe(0)
        ->and(Subscription::query()->where('team_id', $tenant->team_id)->value('stripe_invoice_paid'))->toBeFalse();
});

it('charges upgrades pro rata and schedules downgrades for the renewal', function () {
    interFake();
    $tenant = interTenant('basic', PlatformTenant::STATUS_ACTIVE);
    $tenant->update(['billing_provider' => 'inter', 'billing_interval' => 'monthly', 'current_period_start' => now()->subDays(15), 'current_period_end' => now()->addDays(15)]);

    $result = InterBilling::subscribe($tenant->fresh(), 'pro', 'monthly');
    expect($result['action'])->toBe('upgrade')
        ->and($result['invoice']->type)->toBe('upgrade')
        ->and($result['invoice']->amount_cents)->toBeGreaterThan(4900)->toBeLessThan(5100)
        ->and($tenant->fresh()->plan)->toBe('basic');

    InterBilling::markPaid($result['invoice'], $result['invoice']->amount_cents, 'PIX');
    expect($tenant->fresh()->plan)->toBe('pro');

    $result = InterBilling::subscribe($tenant->fresh(), 'basic', 'monthly');
    expect($result['action'])->toBe('scheduled')
        ->and($tenant->fresh()->pending_plan)->toBe('basic')
        ->and($tenant->fresh()->plan)->toBe('pro');
});

it('refreshes an expired token once and reports the Inter validation errors', function () {
    Http::fake([
        INTER.'/oauth/v2/token' => Http::sequence()->push(['access_token' => 'old', 'expires_in' => 3600])->push(['access_token' => 'new', 'expires_in' => 3600]),
        INTER.'/cobranca/v3/cobrancas' => Http::sequence()
            ->push(['title' => 'Unauthorized'], 401)
            ->push(['codigoSolicitacao' => 'cod-2'])
            ->push(['title' => 'Requisição inválida', 'violacoes' => [['propriedade' => 'pagador.cep', 'razao' => 'CEP inválido']]], 400),
    ]);
    config(['platform.inter.conta_corrente' => '1234567-8']);
    $client = new InterClient;

    expect($client->createCharge(['seuNumero' => 'X']))->toBe('cod-2');
    Http::assertSent(fn (Request $request) => $request->url() === INTER.'/cobranca/v3/cobrancas' && $request->hasHeader('Authorization', 'Bearer new') && $request->hasHeader('x-conta-corrente', '12345678'));

    expect(fn () => $client->createCharge(['seuNumero' => 'Y']))->toThrow(InterException::class, 'pagador.cep: CEP inválido');
});

it('lets the operator store the Inter credentials encrypted and register the webhook', function () {
    interFake();
    config(['platform.inter.client_id' => null, 'platform.inter.client_secret' => null, 'platform.inter.certificate' => null, 'platform.inter.private_key' => null]);
    $root = User::factory()->create();
    $root->teams()->attach(0, ['role' => 'owner']);
    $this->actingAs($root);
    session(['currentTeam' => Team::find(0)]);

    Livewire::test(AdminBilling::class)
        ->set('environment', 'sandbox')
        ->set('clientId', 'my-client')
        ->set('clientSecret', 'my-secret')
        ->set('certificate', "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----")
        ->set('privateKey', "-----BEGIN PRIVATE KEY-----\nBBB\n-----END PRIVATE KEY-----")
        ->set('prices.pro.monthly', '199,90')
        ->call('save')
        ->assertSet('resultOk', true)
        ->assertSet('clientSecret', '')
        ->assertDontSee('my-secret');

    PlatformSetting::flushMemo();
    expect(InterSettings::value('client_secret'))->toBe('my-secret')
        ->and(DB::table('platform_settings')->where('key', 'inter.client_secret')->value('value'))->not->toContain('my-secret')
        ->and(InterBilling::priceCents('pro', 'monthly'))->toBe(19990);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === INTER.'/cobranca/v3/cobrancas/webhook'
        && $request['webhookUrl'] === 'https://painel.hostrema.com.br/api/v1/billing/webhooks/inter/webhooktoken1234567890abcdef');
});

it('asks for the billing details before issuing the first invoice', function () {
    interFake();
    $tenant = interTenant();
    $tenant->update(['billing_profile' => null]);

    Livewire::test(Pricing::class)
        ->call('subscribe', 'pro')
        ->assertSet('editingProfile', true)
        ->assertSet('selectedPlan', 'pro')
        ->set('profile.name', 'Empresa X Ltda')
        ->set('profile.document', '11.222.333/0001-81')
        ->set('profile.email', 'financeiro@example.com')
        ->set('profile.zip', '01310100')
        ->set('profile.street', 'Av. Paulista')
        ->set('profile.city', 'São Paulo')
        ->set('profile.state', 'SP')
        ->call('saveProfileAndSubscribe')
        ->assertHasNoErrors()
        ->assertSet('editingProfile', false);

    $invoice = PlatformInvoice::query()->where('team_id', $tenant->team_id)->first();
    expect($invoice->plan)->toBe('pro')->and(data_get($tenant->fresh()->billing_profile, 'document'))->toBe('11222333000181');
    InterBilling::sync($invoice);
    Livewire::test(Pricing::class)->assertSee('Payment pending')->assertSee('00020101021226990014br.gov.bcb.pix');
});

it('rejects an invalid CPF in the billing details', function () {
    interTenant()->update(['billing_profile' => null]);

    Livewire::test(Pricing::class)
        ->call('subscribe', 'pro')
        ->set('profile.document', '123.456.789-00')
        ->call('saveProfileAndSubscribe')
        ->assertHasErrors(['profile.document']);
});

it('shows the platform name instead of Coolify without touching identifiers', function () {
    expect(PlatformBranding::text('Welcome to Coolify! Coolify\'s proxy: coolify-proxy, docs at https://coolify.io, App\\Actions\\CoolifyTask'))
        ->toBe('Welcome to Hostrema! Hostrema\'s proxy: coolify-proxy, docs at https://coolify.io, App\\Actions\\CoolifyTask');
    expect(Blade::render('<title>{{ $title ?? \'Coolify\' }}</title>'))->toBe('<title>Hostrema</title>');

    config(['platform.name' => "Evil' . phpinfo() . '"]);
    expect(PlatformBranding::text('Coolify'))->toBe('Coolify');
});

it('publishes the plans and prices on a public page', function () {
    $this->withoutVite()->get('/pricing')->assertOk()->assertSee('Hostrema')->assertSee('Profissional')->assertSee('149,90');
});

it('reconciles the raw usage with the hourly rollups', function () {
    $tenant = interTenant('pro', PlatformTenant::STATUS_ACTIVE);
    $hour = now()->subDay()->startOfDay()->addHours(3);
    foreach ([100, 200, 300] as $index => $value) {
        \App\Models\UsageEvent::create(['team_id' => $tenant->team_id, 'metric' => \App\Models\UsageEvent::METRIC_CPU_SECONDS, 'value' => $value, 'recorded_at' => $hour->copy()->addMinutes($index * 5)]);
    }
    \App\Services\Platform\UsageAggregator::aggregateHour($hour);
    expect(\App\Services\Platform\UsageAggregator::reconcileDay(now()->subDay()))->toBe([]);

    \App\Models\AggregatedUsageHourly::query()->where('team_id', $tenant->team_id)->update(['value' => 10]);
    $mismatches = \App\Services\Platform\UsageAggregator::reconcileDay(now()->subDay());
    expect($mismatches)->toHaveCount(1)
        ->and((float) \App\Models\AggregatedUsageHourly::query()->where('team_id', $tenant->team_id)->sum('value'))->toBe(600.0)
        ->and(\App\Models\AuditLog::query()->where('action', 'platform.reconciliation.mismatch')->exists())->toBeTrue();
});
