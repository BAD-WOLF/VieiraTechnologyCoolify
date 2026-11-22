<?php

/*
 * Efí sandbox, tenants and operator shared by the billing tests (PlatformEfiBillingTest,
 * PlatformBillingCasesTest). Loaded with require_once by each of them.
 */

use App\Models\InstanceSettings;
use App\Models\PlatformSetting;
use App\Models\PlatformTenant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const EFI_CHARGES = 'https://cobrancas-h.api.efipay.com.br';
const EFI_PIX = 'https://pix-h.api.efipay.com.br';
const EFI_HOOK = 'https://painel.hostrema.com.br/api/v1/billing/webhooks/efi/webhooktoken1234567890abcdef';

/**
 * Platform with Efí (sandbox) as the payment provider and prices for the plans.
 */
function efiSetUp(): void
{
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
}

function efiTearDown(): void
{
    File::deleteDirectory(storage_path('app/private/platform/efi'));
}

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
