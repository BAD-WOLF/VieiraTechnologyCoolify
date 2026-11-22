<?php

use App\Actions\Platform\ProvisionTenant;
use App\Jobs\PlatformNoticeJob;
use App\Jobs\PlatformSyncInvoiceJob;
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
use App\Services\Platform\Efi\EfiClient;
use App\Services\Platform\Efi\EfiException;
use App\Services\Platform\Efi\EfiSettings;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\PlatformBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

const EFI_CHARGES = 'https://cobrancas-h.api.efipay.com.br';
const EFI_PIX = 'https://pix-h.api.efipay.com.br';
const EFI_HOOK = 'https://painel.hostrema.com.br/api/v1/billing/webhooks/efi/webhooktoken1234567890abcdef';

beforeEach(function () {
    Queue::fake();
    $GLOBALS['efiFaked'] = false;
    PlatformSetting::flushMemo();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.billing.provider' => 'efi',
        'platform.efi.environment' => 'sandbox',
        'platform.efi.client_id' => 'Client_Id_test',
        'platform.efi.client_secret' => 'Client_Secret_test',
        'platform.efi.certificate' => null,
        'platform.efi.private_key' => null,
        'platform.efi.pix_key' => null,
        'platform.efi.webhook_token' => 'webhooktoken1234567890abcdef',
        'platform.plans.basic.price_monthly' => '49.90',
        'platform.plans.pro.price_monthly' => '149.90',
        'platform.plans.pro.price_yearly' => '1499.00',
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.com.br']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/private/platform/efi'));
});

/**
 * A certificate and its key, like the one Efí gives for an application.
 *
 * @return array{certificate: string, key: string, p12: string}
 */
function efiCertificate(string $password = ''): array
{
    static $made = [];
    if (isset($made[$password])) {
        return $made[$password];
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'hostrema-efi-test'], $key, ['digest_alg' => 'sha256']), null, $key, 2, ['digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem);
    openssl_pkcs12_export($certificate, $p12, $key, $password);

    return $made[$password] = ['certificate' => $certificatePem, 'key' => $keyPem, 'p12' => $p12];
}

/**
 * Efí sandbox: Cobranças API (boletos with Pix) and Pix API (charges with a due date).
 *
 * @param  array<string, mixed>  $charge  changes to the boleto returned by GET /v1/charge/{id}
 * @param  array<string, mixed>  $dueCharge  changes to the Pix charge returned by /v2/cobv/{txid}
 * @param  array<int, array<string, mixed>>  $notification  changes behind a notification token
 */
function efiFake(array $charge = [], array $dueCharge = [], array $notification = []): void
{
    $GLOBALS['efiCharge'] = $charge;
    $GLOBALS['efiDueCharge'] = $dueCharge;
    $GLOBALS['efiNotification'] = $notification;
    if ($GLOBALS['efiFaked'] ?? false) {
        return;
    }
    $GLOBALS['efiFaked'] = true;
    $billet = [
        'barcode' => '36490.00019 00000.000001 00000.000000 1 98760000014990',
        'pix' => ['qrcode' => '00020101021226830014BR.GOV.BCB.PIX2561qrcodespix.sejaefi.com.br/bolix/v2/cobv/HR', 'qrcode_image' => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='],
        'link' => 'https://download.sejaefi.com.br/v1/link/1002/',
        'pdf' => ['charge' => 'https://download.sejaefi.com.br/v1/1002.pdf'],
        'expire_at' => '2026-12-01',
    ];
    Http::fake([
        EFI_CHARGES.'/v1/authorize' => Http::response(['access_token' => 'charges-token', 'token_type' => 'Bearer', 'expires_in' => 600]),
        EFI_CHARGES.'/v1/charge/one-step' => Http::response(['code' => 200, 'data' => [...$billet, 'billet_link' => 'https://cobrancas-h.api.efipay.com.br/v1/print/1002', 'charge_id' => 1002, 'status' => 'waiting', 'total' => 14990, 'payment' => 'banking_billet']]),
        EFI_CHARGES.'/v1/notification/*' => fn () => Http::response(['code' => 200, 'data' => $GLOBALS['efiNotification']]),
        EFI_CHARGES.'/v1/charge/*/cancel' => Http::response(['code' => 200]),
        EFI_CHARGES.'/v1/charge/*/settle' => Http::response(['code' => 200]),
        EFI_CHARGES.'/v1/charge/*' => fn () => Http::response(['code' => 200, 'data' => array_replace_recursive([
            'charge_id' => 1002, 'total' => 14990, 'status' => 'waiting', 'custom_id' => null,
            'payment' => ['method' => 'banking_billet', 'banking_billet' => $billet],
        ], $GLOBALS['efiCharge'])]),
        EFI_PIX.'/oauth/token' => Http::response(['access_token' => 'pix-token', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'cobv.write cobv.read webhook.write webhook.read']),
        EFI_PIX.'/v2/webhook/*' => Http::response(['webhookUrl' => EFI_HOOK]),
        EFI_PIX.'/v2/cobv/*' => fn (Request $request) => Http::response(array_replace_recursive([
            'txid' => basename((string) parse_url($request->url(), PHP_URL_PATH)),
            'status' => 'ATIVA',
            'pixCopiaECola' => '00020101021226830014BR.GOV.BCB.PIX2561qrcodespix.sejaefi.com.br/v2/cobv/0001',
        ], $GLOBALS['efiDueCharge'])),
    ]);
}

function efiTenant(?string $plan = null, string $status = PlatformTenant::STATUS_PENDING): PlatformTenant
{
    $user = User::factory()->create();
    $team = $user->teams()->first();
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(['plan' => $plan, 'status' => $status, 'billing_profile' => [
        'name' => 'Maria Silva', 'document' => '52998224725', 'email' => 'maria@example.com', 'phone' => '(11) 98888-7777',
    ]]);

    return $tenant->fresh();
}

/**
 * Efí account with the certificate of the application and a Pix key: invoices are Pix charges.
 */
function efiPixTenant(): PlatformTenant
{
    $certificate = efiCertificate();
    config(['platform.efi.pix_key' => '11222333000181', 'platform.efi.certificate' => $certificate['certificate'], 'platform.efi.private_key' => $certificate['key']]);
    $tenant = efiTenant();
    $tenant->update(['billing_profile' => ['name' => 'Maria Silva', 'document' => '52998224725', 'email' => 'maria@example.com']]);

    return $tenant->fresh();
}

function efiOperator(): User
{
    $root = User::factory()->create();
    $root->teams()->attach(0, ['role' => 'owner']);
    test()->actingAs($root);
    session(['currentTeam' => Team::find(0)]);

    return $root;
}

it('parses prices and validates the payer documents', function () {
    expect(PlatformBilling::toCents('49.90'))->toBe(4990)
        ->and(PlatformBilling::toCents('49,90'))->toBe(4990)
        ->and(PlatformBilling::toCents('1.499,00'))->toBe(149900)
        ->and(PlatformBilling::toCents('1,499.00'))->toBe(149900)
        ->and(PlatformBilling::toCents(''))->toBeNull()
        ->and(PlatformBilling::toCents('0'))->toBeNull()
        ->and(BillingProfile::validDocument('529.982.247-25'))->toBeTrue()
        ->and(BillingProfile::validDocument('11.222.333/0001-81'))->toBeTrue()
        ->and(BillingProfile::validDocument('111.111.111-11'))->toBeFalse()
        ->and(BillingProfile::validDocument('12345678900'))->toBeFalse()
        ->and(BillingProfile::localPhone('+55 (11) 98888-7777'))->toBe('11988887777');

    $tenant = efiTenant();
    expect(BillingProfile::boletoCustomer($tenant))->toBe([
        'name' => 'Maria Silva', 'cpf' => '52998224725', 'email' => 'maria@example.com', 'phone_number' => '11988887777',
    ]);
    $tenant->update(['billing_profile' => ['name' => 'Empresa X Ltda', 'document' => '11.222.333/0001-81', 'email' => '', 'phone' => '']]);
    expect(BillingProfile::boletoCustomer($tenant->fresh()))->toBe(['juridical_person' => ['corporate_name' => 'Empresa X Ltda', 'cnpj' => '11222333000181']])
        ->and(BillingProfile::debtor($tenant->fresh()))->toBe(['cnpj' => '11222333000181', 'nome' => 'Empresa X Ltda']);
});

it('reads the .p12 and .pem certificates of Efí and writes them only for the owner of the files', function () {
    $made = efiCertificate();
    [$certificate, $key] = EfiSettings::readCertificate($made['p12']);
    expect(openssl_x509_check_private_key($certificate, $key))->toBeTrue()
        ->and(openssl_x509_parse($certificate)['subject']['CN'])->toBe('hostrema-efi-test');

    // The .pem converted from the .p12 (certificate and key in one file), with a CA certificate first.
    [$certificate] = EfiSettings::readCertificate(efiCertificate('other')['certificate'].$made['key'].$made['certificate']);
    expect(openssl_x509_check_private_key($certificate, $made['key']))->toBeTrue();

    expect(fn () => EfiSettings::readCertificate($made['certificate']))->toThrow(EfiException::class, 'certificate and the private key')
        ->and(fn () => EfiSettings::readCertificate(efiCertificate('secret')['p12'], 'wrong'))->toThrow(EfiException::class, 'could not be read')
        ->and(fn () => EfiSettings::readCertificate(efiCertificate('other')['certificate'].$made['key']))->toThrow(EfiException::class, 'does not belong')
        ->and(EfiSettings::readCertificate(efiCertificate('secret')['p12'], 'secret')[0])->toBe(trim(efiCertificate('secret')['certificate'])."\n");

    // Stored as text, base64 or a file path; written 0600 for mutual TLS, old files removed.
    config(['platform.efi.certificate' => base64_encode($made['certificate'])]);
    expect(EfiSettings::pem('certificate'))->toBe(trim($made['certificate'])."\n");
    $first = EfiSettings::certificatePath();
    expect(file_get_contents($first))->toBe(trim($made['certificate'])."\n")
        ->and(substr(sprintf('%o', fileperms($first)), -3))->toBe('600');
    config(['platform.efi.certificate' => efiCertificate('other')['certificate']]);
    $second = EfiSettings::certificatePath();
    expect($second)->not->toBe($first)->and(file_exists($first))->toBeFalse();

    config(['platform.efi.certificate' => 'not a certificate', 'platform.efi.pix_key' => 'chave@example.com']);
    expect(EfiSettings::pem('certificate'))->toBeNull()
        ->and(EfiSettings::isConfigured())->toBeTrue()
        ->and(EfiSettings::pixReady())->toBeFalse();
});

it('opens the .p12 files made with the legacy algorithms of Efí through the openssl command', function () {
    $made = efiCertificate();
    $directory = sys_get_temp_dir().'/efi-legacy-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory, 0700);
    file_put_contents("{$directory}/certificate.pem", $made['certificate']);
    file_put_contents("{$directory}/key.pem", $made['key']);
    $export = new Process(['openssl', 'pkcs12', '-export', '-legacy', '-in', "{$directory}/certificate.pem", '-inkey', "{$directory}/key.pem", '-out', "{$directory}/legacy.p12", '-passout', 'pass:']);
    $export->run();
    $legacy = (string) @file_get_contents("{$directory}/legacy.p12");
    File::deleteDirectory($directory);
    expect($export->isSuccessful())->toBeTrue();

    [$certificate, $key] = EfiSettings::readCertificate($legacy);
    expect(trim($certificate))->toBe(trim($made['certificate']))
        ->and(openssl_x509_check_private_key($certificate, $key))->toBeTrue();
})->skip(fn () => is_null((new ExecutableFinder)->find('openssl')), 'needs the openssl command');

it('charges the first period with a boleto with Pix and activates the plan when it is paid', function () {
    efiFake();
    $tenant = efiTenant();

    $result = PlatformBilling::subscribe($tenant, 'pro', 'monthly');
    $invoice = $result['invoice'];
    expect($result['action'])->toBe('checkout')
        ->and($invoice->amount_cents)->toBe(14990)
        ->and($invoice->provider)->toBe('efi')
        ->and($invoice->provider_reference)->toBe('1002')
        ->and(PlatformBilling::isBoleto($invoice))->toBeTrue()
        ->and($invoice->number)->toStartWith('HR')
        ->and($invoice->payment_details)->toMatchArray([
            'linha_digitavel' => '36490.00019 00000.000001 00000.000000 1 98760000014990',
            'pix_copia_e_cola' => '00020101021226830014BR.GOV.BCB.PIX2561qrcodespix.sejaefi.com.br/bolix/v2/cobv/HR',
            'boleto_url' => 'https://download.sejaefi.com.br/v1/1002.pdf',
        ])
        ->and($tenant->fresh()->plan)->toBeNull();

    Http::assertSent(fn (Request $request) => $request->url() === EFI_CHARGES.'/v1/authorize'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('Client_Id_test:Client_Secret_test'))
        && $request['grant_type'] === 'client_credentials');
    Http::assertSent(function (Request $request) use ($invoice) {
        $billet = $request['payment']['banking_billet'] ?? [];

        return $request->method() === 'POST' && $request->url() === EFI_CHARGES.'/v1/charge/one-step'
            && $request->hasHeader('Authorization', 'Bearer charges-token')
            && $request['items'][0]['value'] === 14990 && $request['items'][0]['amount'] === 1
            && $request['metadata'] === ['custom_id' => $invoice->number, 'notification_url' => EFI_HOOK.'/charges']
            && $billet['customer'] === ['name' => 'Maria Silva', 'cpf' => '52998224725', 'email' => 'maria@example.com', 'phone_number' => '11988887777']
            && $billet['expire_at'] === $invoice->due_date->toDateString()
            && $billet['configurations'] === ['fine' => 200, 'interest' => 33, 'days_to_write_off' => 30]
            && strlen($billet['message']) <= 100;
    });
    Queue::assertPushed(PlatformNoticeJob::class);

    // Boleto notification: only a token, whose changes are read from Efí.
    $this->post('/api/v1/billing/webhooks/efi/wrong-token-0000000000000/charges', ['notification' => 'aaaaaaaa-1111'])->assertNotFound();
    $this->post(EFI_HOOK.'/charges', ['notification' => 'not a token!'])->assertStatus(400);
    efiFake(notification: [
        ['id' => 1, 'type' => 'charge', 'custom_id' => $invoice->number, 'status' => ['current' => 'waiting', 'previous' => 'new'], 'identifiers' => ['charge_id' => 1002]],
        ['id' => 2, 'type' => 'charge', 'custom_id' => $invoice->number, 'status' => ['current' => 'paid', 'previous' => 'waiting'], 'identifiers' => ['charge_id' => 1002], 'value' => 15290, 'received_by_bank_at' => '2026-11-20'],
        ['id' => 3, 'type' => 'charge', 'custom_id' => 'HR-OTHER', 'status' => ['current' => 'paid', 'previous' => 'waiting'], 'identifiers' => ['charge_id' => 9999], 'value' => 100],
    ]);
    $this->post(EFI_HOOK.'/charges', ['notification' => 'c6b5ff34-2f4e-4a6b-9b0b-3c0c6bd1a0f1'])->assertOk()->assertJson(['received' => 1]);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === EFI_CHARGES.'/v1/notification/c6b5ff34-2f4e-4a6b-9b0b-3c0c6bd1a0f1');
    Queue::assertPushed(PlatformSyncInvoiceJob::class, fn (PlatformSyncInvoiceJob $job) => $job->invoiceId === $invoice->id && $job->amountPaidCents === 15290);

    // The charge is read back before the plan is activated.
    efiFake(['status' => 'paid']);
    (new PlatformSyncInvoiceJob($invoice->id, 0, 15290))->handle();

    $invoice->refresh();
    $tenant->refresh();
    expect($invoice->status)->toBe(PlatformInvoice::STATUS_PAID)
        ->and($invoice->payment_method)->toBe('BOLETO')
        ->and($invoice->amount_paid_cents)->toBe(15290)
        ->and($tenant->plan)->toBe('pro')
        ->and($tenant->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->billing_provider)->toBe('efi')
        ->and($tenant->current_period_end->isFuture())->toBeTrue()
        ->and(Subscription::query()->where('team_id', $tenant->team_id)->value('stripe_invoice_paid'))->toBeTrue()
        ->and(PlatformSetting::get('efi.last_webhook_at'))->not->toBeNull();
    Queue::assertPushed(fn (\Lorisleiva\Actions\Decorators\JobDecorator $job) => $job->getAction() instanceof ProvisionTenant);
});

it('ignores a forged payment notice and voids a boleto Efí cancelled or expired', function () {
    efiFake();
    $invoice = PlatformBilling::subscribe(efiTenant(), 'pro', 'monthly')['invoice'];

    // The notification says "paid", but Efí still has the boleto waiting.
    efiFake(notification: [['status' => ['current' => 'paid'], 'identifiers' => ['charge_id' => 1002], 'value' => 14990]]);
    $this->post(EFI_HOOK.'/charges', ['notification' => 'c6b5ff34-2f4e-4a6b-9b0b-3c0c6bd1a0f1'])->assertOk();
    efiFake(['status' => 'waiting']);
    (new PlatformSyncInvoiceJob($invoice->id, 0, 14990))->handle();
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_OPEN);

    efiFake(['status' => 'expired']);
    $invoice = PlatformBilling::sync($invoice->fresh());
    expect($invoice->status)->toBe(PlatformInvoice::STATUS_VOID)
        ->and(data_get($invoice->metadata, 'void_reason'))->toBe('expired');
});

it('renews at the end of the period, marks overdue invoices and never duplicates them', function () {
    efiFake();
    $tenant = efiTenant('pro', PlatformTenant::STATUS_ACTIVE);
    $tenant->update(['billing_provider' => 'efi', 'billing_interval' => 'monthly', 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subMinute()]);

    PlatformBilling::runCycle();
    PlatformBilling::runCycle();

    $renewals = PlatformInvoice::query()->where('team_id', $tenant->team_id)->where('type', 'subscription')->get();
    expect($renewals)->toHaveCount(1)
        ->and($renewals->first()->period_start->equalTo($tenant->current_period_end))->toBeTrue()
        ->and($renewals->first()->amount_cents)->toBe(14990)
        ->and($renewals->first()->provider_reference)->toBe('1002');

    $renewals->first()->update(['due_date' => now()->subDays(2)->toDateString()]);
    PlatformBilling::markOverdue();
    expect($tenant->fresh()->past_due_since)->not->toBeNull();

    PlatformBilling::markPaid($renewals->first()->fresh(), 14990, 'BOLETO');
    $tenant->refresh();
    expect($tenant->past_due_since)->toBeNull()
        ->and($tenant->current_period_start->equalTo($renewals->first()->period_start))->toBeTrue()
        ->and($tenant->current_period_end->isFuture())->toBeTrue();
});

it('ends the subscription at the end of the period when cancelled', function () {
    efiFake();
    $tenant = efiTenant('pro', PlatformTenant::STATUS_ACTIVE);
    $tenant->update(['billing_provider' => 'efi', 'billing_interval' => 'monthly', 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->addDay()]);
    PlatformBilling::cancelAtPeriodEnd($tenant->fresh());
    expect(Subscription::query()->where('team_id', $tenant->team_id)->value('stripe_cancel_at_period_end'))->toBeTrue();

    $tenant->update(['current_period_end' => now()->subMinute()]);
    PlatformBilling::renewDue();

    $tenant->refresh();
    expect($tenant->status)->toBe(PlatformTenant::STATUS_CANCELLED)
        ->and($tenant->suspended_at)->not->toBeNull()
        ->and(PlatformInvoice::query()->where('team_id', $tenant->team_id)->count())->toBe(0)
        ->and(Subscription::query()->where('team_id', $tenant->team_id)->value('stripe_invoice_paid'))->toBeFalse();
});

it('charges upgrades pro rata and schedules downgrades for the renewal', function () {
    efiFake();
    $tenant = efiTenant('basic', PlatformTenant::STATUS_ACTIVE);
    $tenant->update(['billing_provider' => 'efi', 'billing_interval' => 'monthly', 'current_period_start' => now()->subDays(15), 'current_period_end' => now()->addDays(15)]);

    $result = PlatformBilling::subscribe($tenant->fresh(), 'pro', 'monthly');
    expect($result['action'])->toBe('upgrade')
        ->and($result['invoice']->type)->toBe('upgrade')
        ->and($result['invoice']->amount_cents)->toBeGreaterThan(4900)->toBeLessThan(5100)
        ->and($tenant->fresh()->plan)->toBe('basic');

    PlatformBilling::markPaid($result['invoice'], $result['invoice']->amount_cents, 'PIX');
    expect($tenant->fresh()->plan)->toBe('pro');

    $result = PlatformBilling::subscribe($tenant->fresh(), 'basic', 'monthly');
    expect($result['action'])->toBe('scheduled')
        ->and($tenant->fresh()->pending_plan)->toBe('basic')
        ->and($tenant->fresh()->plan)->toBe('pro');
});

it('refreshes an expired token once and reports the Efí validation errors', function () {
    Http::fake([
        EFI_CHARGES.'/v1/authorize' => Http::sequence()->push(['access_token' => 'old', 'expires_in' => 600])->push(['access_token' => 'new', 'expires_in' => 600]),
        EFI_CHARGES.'/v1/charge/one-step' => Http::sequence()
            ->push(['error' => 'unauthorized', 'error_description' => 'Token expired'], 401)
            ->push(['code' => 200, 'data' => ['charge_id' => 2002, 'status' => 'waiting']])
            ->push(['code' => 3500034, 'error' => 'validation_error', 'error_description' => ['property' => '/payment/banking_billet/customer/phone_number', 'message' => 'O campo não atende o formato']], 400),
    ]);
    $client = new EfiClient;

    expect($client->createBoleto(['items' => []])['charge_id'])->toBe(2002);
    Http::assertSent(fn (Request $request) => $request->url() === EFI_CHARGES.'/v1/charge/one-step' && $request->hasHeader('Authorization', 'Bearer new'));

    expect(fn () => $client->createBoleto(['items' => []]))->toThrow(EfiException::class, '/payment/banking_billet/customer/phone_number: O campo não atende o formato');
});

it('lets the operator store the Efí credentials and certificate encrypted and connect the Pix webhook', function () {
    efiFake();
    config(['platform.efi.client_id' => null, 'platform.efi.client_secret' => null]);
    efiOperator();
    $made = efiCertificate();

    Livewire::test(AdminBilling::class)
        ->assertSee('Not configured')
        ->set('environment', 'sandbox')
        ->set('clientId', 'Client_Id_live')
        ->set('clientSecret', 'Client_Secret_live')
        ->set('pixKey', 'financeiro@hostrema.com.br')
        ->set('certificateFile', UploadedFile::fake()->createWithContent('homologacao.p12', $made['p12']))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('resultOk', true)
        ->assertSet('clientSecret', '')
        ->assertSet('certificateFile', null)
        ->assertSee('Pix ready')
        ->assertDontSee('Client_Secret_live')
        ->assertDontSee('PRIVATE KEY');

    PlatformSetting::flushMemo();
    expect(EfiSettings::value('client_secret'))->toBe('Client_Secret_live')
        ->and(EfiSettings::pem('certificate'))->toBe(trim($made['certificate'])."\n")
        ->and(openssl_x509_check_private_key(EfiSettings::pem('certificate'), EfiSettings::pem('private_key')))->toBeTrue()
        ->and(DB::table('platform_settings')->where('key', 'efi.client_secret')->value('value'))->not->toContain('Client_Secret_live')
        ->and(DB::table('platform_settings')->where('key', 'efi.private_key')->value('value'))->not->toContain('PRIVATE KEY')
        ->and(BillingProfile::methods())->toBe([BillingProfile::PIX, BillingProfile::BOLETO])
        ->and(PlatformSetting::get('efi.webhook_registered_at'))->not->toBeNull();
    Http::assertSent(fn (Request $request) => $request->url() === EFI_PIX.'/oauth/token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('Client_Id_live:Client_Secret_live')));
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === EFI_PIX.'/v2/webhook/financeiro%40hostrema.com.br'
        && $request['webhookUrl'] === EFI_HOOK
        && $request->hasHeader('x-skip-mtls-checking', 'true')
        && $request->hasHeader('Authorization', 'Bearer pix-token'));
});

it('refuses a certificate that is not the one of the Efí application', function () {
    efiOperator();

    Livewire::test(AdminBilling::class)
        ->set('certificateFile', UploadedFile::fake()->createWithContent('certificate.pem', efiCertificate()['certificate']))
        ->call('save')
        ->assertHasErrors(['certificateFile']);

    expect(EfiSettings::pem('certificate'))->toBeNull();
});

it('asks for the billing details before issuing the first invoice', function () {
    efiFake();
    $tenant = efiTenant();
    $tenant->update(['billing_profile' => null]);

    Livewire::test(Pricing::class)
        ->call('subscribe', 'pro')
        ->assertSet('editingProfile', true)
        ->assertSet('selectedPlan', 'pro')
        ->assertDontSee('ZIP code')
        ->set('profile.name', 'Empresa X Ltda')
        ->set('profile.document', '11.222.333/0001-81')
        ->set('profile.email', 'financeiro@example.com')
        ->call('saveProfileAndSubscribe')
        ->assertHasErrors(['profile.phone'])
        ->set('profile.phone', '(11) 3333-4444')
        ->call('saveProfileAndSubscribe')
        ->assertHasNoErrors()
        ->assertSet('editingProfile', false)
        ->assertSee('Payment pending')
        ->assertSee('qrcodespix.sejaefi.com.br/bolix')
        ->assertSee('Download boleto (PDF)')
        ->assertSee('https://download.sejaefi.com.br/v1/1002.pdf');

    $invoice = PlatformInvoice::query()->where('team_id', $tenant->team_id)->firstOrFail();
    expect($invoice->plan)->toBe('pro')
        ->and(data_get($tenant->fresh()->billing_profile, 'document'))->toBe('11222333000181');
    Http::assertSent(fn (Request $request) => $request->url() === EFI_CHARGES.'/v1/charge/one-step'
        && $request['payment']['banking_billet']['customer'] === ['juridical_person' => ['corporate_name' => 'Empresa X Ltda', 'cnpj' => '11222333000181'], 'email' => 'financeiro@example.com', 'phone_number' => '1133334444']);

    // The billing page shows the same boleto, and its button opens the PDF at Efí.
    Livewire::test(\App\Livewire\Platform\Billing::class)
        ->assertSee('Empresa X Ltda · financeiro@example.com')
        ->assertSee('· (11) 3333-4444')
        ->assertSee('https://download.sejaefi.com.br/v1/1002.pdf')
        ->call('downloadBoleto', $invoice->uuid)
        ->assertRedirect('https://download.sejaefi.com.br/v1/1002.pdf');
});

it('rejects an invalid CPF in the billing details', function () {
    efiTenant()->update(['billing_profile' => null]);

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
    $tenant = efiTenant('pro', PlatformTenant::STATUS_ACTIVE);
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

it('charges by Pix with only name and CPF when the account has a Pix key and the certificate', function () {
    efiFake();
    $tenant = efiPixTenant();
    expect(BillingProfile::isComplete($tenant))->toBeTrue()
        ->and(BillingProfile::methodOf($tenant))->toBe('pix');

    $invoice = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];

    expect(PlatformBilling::isPix($invoice))->toBeTrue()
        ->and(strlen($invoice->provider_reference))->toBe(32)
        ->and($invoice->provider_reference)->toStartWith((string) $invoice->number)
        ->and(data_get($invoice->payment_details, 'pix_copia_e_cola'))->toStartWith('000201')
        ->and($invoice->provider_status)->toBe('ATIVA');
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === EFI_PIX.'/v2/cobv/'.$invoice->provider_reference
        && $request['devedor'] === ['cpf' => '52998224725', 'nome' => 'Maria Silva']
        && $request['valor']['original'] === '149.90'
        && $request['valor']['multa'] === ['modalidade' => 2, 'valorPerc' => '2.00']
        && $request['valor']['juros'] === ['modalidade' => 3, 'valorPerc' => '1.00']
        && $request['chave'] === '11222333000181'
        && $request['calendario'] === ['dataDeVencimento' => $invoice->due_date->toDateString(), 'validadeAposVencimento' => 30]
        && $request->hasHeader('Authorization', 'Bearer pix-token'));
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), EFI_CHARGES));
    Queue::assertPushed(PlatformNoticeJob::class);

    // Test notification of the registration, then a Pix of the key (Efí adds "/pix").
    $this->postJson(EFI_HOOK, ['evento' => 'teste_webhook'])->assertOk()->assertJson(['received' => 0]);
    $this->call('POST', EFI_HOOK, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'ping')->assertOk();
    $this->postJson('/api/v1/billing/webhooks/efi/wrong-token-0000000000000/pix', ['pix' => [['txid' => $invoice->provider_reference]]])->assertNotFound();
    $this->postJson(EFI_HOOK.'/pix', ['pix' => [['txid' => $invoice->provider_reference, 'endToEndId' => 'E1', 'valor' => '1.00']]])
        ->assertOk()->assertJson(['received' => 1]);
    Queue::assertPushed(PlatformSyncInvoiceJob::class, fn (PlatformSyncInvoiceJob $job) => $job->invoiceId === $invoice->id);

    // The value comes from the Pix API, not from the notification.
    efiFake(dueCharge: ['status' => 'CONCLUIDA', 'pix' => [['endToEndId' => 'E1', 'txid' => $invoice->provider_reference, 'valor' => '149.90', 'horario' => '2026-11-16T10:00:00.000Z']]]);
    (new PlatformSyncInvoiceJob($invoice->id))->handle();

    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_PAID)
        ->and($invoice->fresh()->amount_paid_cents)->toBe(14990)
        ->and($invoice->fresh()->payment_method)->toBe('PIX')
        ->and($tenant->fresh()->plan)->toBe('pro')
        ->and($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_ACTIVE);
});

it('asks for a phone number only from customers who pay with boleto', function () {
    efiFake();
    $tenant = efiPixTenant();
    $tenant->update(['billing_profile' => null]);

    Livewire::test(Pricing::class)
        ->call('subscribe', 'pro')
        ->assertSet('editingProfile', true)
        ->assertSet('profile.payment_method', 'pix')
        ->assertSee('Phone (optional)')
        ->set('profile.name', 'Empresa X Ltda')
        ->set('profile.document', '11.222.333/0001-81')
        ->set('profile.email', 'financeiro@example.com')
        ->set('profile.payment_method', 'boleto')
        ->assertSee('Phone with area code')
        ->call('saveProfileAndSubscribe')
        ->assertHasErrors(['profile.phone'])
        ->set('profile.payment_method', 'pix')
        ->call('saveProfileAndSubscribe')
        ->assertHasNoErrors()
        ->assertSet('editingProfile', false)
        ->assertSee('Payment pending')
        ->assertSee('Back to home')
        ->assertDontSee('Download boleto (PDF)');

    $invoice = PlatformInvoice::query()->where('team_id', $tenant->team_id)->firstOrFail();
    expect(PlatformBilling::isPix($invoice))->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_contains($request->url(), '/v2/cobv/') && $request['devedor'] === ['cnpj' => '11222333000181', 'nome' => 'Empresa X Ltda']);
});

it('switches an open Pix charge to a boleto once the phone number is given', function () {
    efiFake();
    $tenant = efiPixTenant();
    $pix = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];

    Livewire::test(Pricing::class)
        ->assertSee('Prefer a boleto?')
        ->call('payWith', 'boleto')
        ->assertSet('editingProfile', true)
        ->assertSet('profile.payment_method', 'boleto')
        ->set('profile.phone', '11 98888-7777')
        ->call('saveProfileAndSubscribe')
        ->assertHasNoErrors();

    $invoice = $pix->fresh();
    expect(PlatformBilling::isBoleto($invoice))->toBeTrue()
        ->and($invoice->provider_reference)->toBe('1002')
        ->and($invoice->status)->toBe(PlatformInvoice::STATUS_OPEN);
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request->url() === EFI_PIX.'/v2/cobv/'.$pix->provider_reference
        && $request['status'] === 'REMOVIDA_PELO_USUARIO_RECEBEDOR');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === EFI_CHARGES.'/v1/charge/one-step'
        && $request['payment']['banking_billet']['customer']['phone_number'] === '11988887777' && $request->hasHeader('Authorization', 'Bearer charges-token'));

    Livewire::test(Pricing::class)->assertSee('Download boleto (PDF)')->assertSee('Pay with Pix instead');
});

it('confirms the payment on the page and leads to the dashboard', function () {
    efiFake();
    $tenant = efiPixTenant();
    $invoice = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];

    $page = Livewire::test(Pricing::class)->assertSee('Payment pending')->assertSet('watchedInvoice', $invoice->uuid);

    efiFake(dueCharge: ['status' => 'CONCLUIDA', 'pix' => [['endToEndId' => 'E2', 'txid' => $invoice->provider_reference, 'valor' => '149.90', 'horario' => '2026-11-16T10:00:00.000Z']]]);
    $page->call('checkPayment', $invoice->uuid)
        ->assertDontSee('Payment pending')
        ->assertSee('Payment confirmed')
        ->assertSee('Go to the dashboard');
    expect($tenant->fresh()->plan)->toBe('pro');
});

it('lets a customer without an active plan open the dashboard, which shows how to activate it', function () {
    $tenant = efiPixTenant();

    $this->withoutVite()->get('/')->assertOk()->assertSee('Your plan is not active yet')
        ->assertSee('Your applications, databases and services.')->assertDontSee('self-hosted');
    $this->get('/projects')->assertRedirect(route('subscription.index'));

    PlatformBilling::markPaid(PlatformBilling::createInvoice($tenant, PlatformInvoice::TYPE_SUBSCRIPTION, 'pro', 'monthly', now(), now()->addMonth(), [PlatformBilling::planLine('pro', 'monthly', 14990)]), 14990, 'PIX');
    \Illuminate\Support\Facades\Cache::forget('team:'.auth()->id());
    $this->get('/')->assertDontSee('Your plan is not active yet');
});

it('simulates payments in the sandbox only, settling the boleto at Efí', function () {
    efiFake();
    $invoice = PlatformBilling::subscribe(efiTenant(), 'pro', 'monthly')['invoice'];

    efiFake(['status' => 'settled']);
    PlatformBilling::simulatePayment($invoice);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->url() === EFI_CHARGES.'/v1/charge/1002/settle');
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_PAID)
        ->and($invoice->fresh()->payment_method)->toBe('MANUAL');

    config(['platform.efi.environment' => 'production']);
    expect(EfiSettings::chargesUrl())->toBe(EfiSettings::CHARGES_PRODUCTION_URL)
        ->and(EfiSettings::pixUrl())->toBe(EfiSettings::PIX_PRODUCTION_URL)
        ->and(fn () => PlatformBilling::simulatePayment($invoice->fresh()))->toThrow(\RuntimeException::class);
});

it('moves the customers, invoices and settings of Banco Inter to Efí', function () {
    $tenant = efiTenant('pro', PlatformTenant::STATUS_ACTIVE);
    $tenant->update(['billing_provider' => 'inter']);
    $open = PlatformBilling::createInvoice($tenant, PlatformInvoice::TYPE_SUBSCRIPTION, 'pro', 'monthly', now(), now()->addMonth(), [PlatformBilling::planLine('pro', 'monthly', 14990)]);
    $open->forceFill(['provider' => 'inter', 'provider_reference' => 'cod-1', 'provider_status' => 'A_RECEBER', 'payment_details' => ['linha_digitavel' => '0779'], 'metadata' => ['rail' => 'boleto', 'notified_at' => now()->toIso8601String()]])->save();
    $paid = PlatformBilling::createInvoice($tenant, PlatformInvoice::TYPE_SUBSCRIPTION, 'pro', 'monthly', now()->subMonth(), now(), [PlatformBilling::planLine('pro', 'monthly', 14990)]);
    $paid->forceFill(['provider' => 'inter', 'provider_reference' => 'cod-0', 'status' => PlatformInvoice::STATUS_PAID])->save();
    PlatformSetting::put('inter.client_secret', 'old-secret');
    PlatformSetting::put('inter.webhook_registered_at', now()->toIso8601String());
    DB::table('platform_settings')->updateOrInsert(['key' => 'config:platform.billing.provider'], ['value' => Crypt::encryptString(json_encode('inter')), 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2025_10_20_000011_move_platform_billing_to_efi.php'))->up();

    $open->refresh();
    expect($tenant->fresh()->billing_provider)->toBe('efi')
        ->and($open->provider)->toBe('efi')
        ->and($open->provider_reference)->toBeNull()
        ->and($open->payment_details)->toBeNull()
        ->and(data_get($open->metadata, 'previous_reference'))->toBe('cod-1')
        ->and(data_get($open->metadata, 'notified_at'))->toBeNull()
        ->and($paid->fresh()->provider)->toBe('efi')
        ->and($paid->fresh()->provider_reference)->toBe('cod-0')
        ->and(DB::table('platform_settings')->where('key', 'like', 'inter.%')->exists())->toBeFalse()
        ->and(json_decode(Crypt::decryptString(DB::table('platform_settings')->where('key', 'config:platform.billing.provider')->value('value')), true))->toBe('efi');

    // The next billing cycle issues the open invoice again through Efí.
    efiFake();
    PlatformBilling::reconcileOpen();
    expect($open->fresh()->provider_reference)->toBe('1002');
});
