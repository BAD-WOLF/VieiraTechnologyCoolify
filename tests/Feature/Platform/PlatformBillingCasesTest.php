<?php

use App\Jobs\PlatformBillingCycleJob;
use App\Jobs\PlatformNoticeJob;
use App\Jobs\PlatformSyncInvoiceJob;
use App\Livewire\Platform\AdminBilling;
use App\Livewire\Platform\Billing;
use App\Livewire\Platform\Pricing;
use App\Models\AggregatedUsageHourly;
use App\Models\AuditLog;
use App\Models\InstanceSettings;
use App\Models\PlatformInvoice;
use App\Models\PlatformTenant;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Platform\Efi\EfiClient;
use App\Services\Platform\Efi\EfiException;
use App\Services\Platform\Efi\EfiSettings;
use App\Services\Platform\InvoiceUsageExport;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\PlatformConfig;
use App\Services\Platform\TenantLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

require_once __DIR__.'/Support/efi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    efiSetUp();
    PlatformConfig::flush();
    // Error pages (404) are rendered without the front-end build, which CI does not have.
    $this->withoutVite();
});

afterEach(function () {
    efiTearDown();
    PlatformConfig::flush();
});

/**
 * Tenant on a paid plan, charged through Efí, in the middle of a monthly period.
 */
function billedTenant(string $plan = 'basic', ?\Carbon\CarbonInterface $periodEnd = null): PlatformTenant
{
    $tenant = efiTenant($plan, PlatformTenant::STATUS_ACTIVE);
    $tenant->update([
        'billing_provider' => PlatformBilling::PROVIDER,
        'billing_interval' => 'monthly',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => $periodEnd ?? now()->addMonth(),
        'provisioned_at' => now()->subMonths(2),
    ]);

    return $tenant->fresh();
}

/**
 * Open invoice of a period of its own (a team has one invoice of each type per period).
 */
function openInvoice(PlatformTenant $tenant, array $changes = [], string $type = PlatformInvoice::TYPE_SUBSCRIPTION, string $plan = 'basic'): PlatformInvoice
{
    static $periods = 0;
    $start = now()->subMonths(++$periods * 2);
    $invoice = PlatformBilling::createInvoice($tenant, $type, $plan, 'monthly', $start, $start->copy()->addMonth(), [PlatformBilling::planLine($plan, 'monthly', (int) PlatformBilling::priceCents($plan, 'monthly'))]);
    if ($changes !== []) {
        $invoice->forceFill($changes)->save();
    }

    return $invoice->fresh();
}

function audited(string $action): int
{
    return AuditLog::query()->where('action', $action)->count();
}

/**
 * Member of the tenant's team without the owner or admin role.
 */
function teamMember(PlatformTenant $tenant): User
{
    $member = User::factory()->create();
    $member->teams()->attach($tenant->team_id, ['role' => 'member']);
    test()->actingAs($member);
    session(['currentTeam' => Team::find($tenant->team_id)]);

    return $member;
}

it('turns prices typed in any format into cents and refuses what is not a price', function () {
    expect(PlatformBilling::toCents(49.9))->toBe(4990)
        ->and(PlatformBilling::toCents(10))->toBe(1000)
        ->and(PlatformBilling::toCents(0))->toBeNull()
        ->and(PlatformBilling::toCents(-3.5))->toBeNull()
        ->and(PlatformBilling::toCents('R$ 1.499,00'))->toBe(149900)
        ->and(PlatformBilling::toCents('1,499.00'))->toBe(149900)
        ->and(PlatformBilling::toCents('0,00'))->toBeNull()
        ->and(PlatformBilling::toCents('1.2.3'))->toBeNull()
        ->and(PlatformBilling::toCents(''))->toBeNull()
        ->and(PlatformBilling::toCents('grátis'))->toBeNull()
        ->and(PlatformBilling::toCents(null))->toBeNull();
});

it('refuses a checkout for plans sold by contact or without a price, and before the billing details', function () {
    efiFake();
    $tenant = efiTenant();

    expect(fn () => PlatformBilling::subscribe($tenant, 'enterprise', 'monthly'))->toThrow(\RuntimeException::class, 'This plan is not available for self-service checkout.')
        ->and(fn () => PlatformBilling::subscribe($tenant, 'does-not-exist', 'monthly'))->toThrow(\RuntimeException::class, 'This plan is not available for self-service checkout.');

    $tenant->update(['billing_profile' => null]);
    expect(fn () => PlatformBilling::subscribe($tenant->fresh(), 'pro', 'monthly'))->toThrow(\RuntimeException::class, 'Fill in the billing details first.')
        ->and(PlatformInvoice::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps the plan picked again and drops a scheduled change or cancellation', function () {
    $tenant = billedTenant('pro');
    $tenant->update(['pending_plan' => 'basic', 'pending_interval' => 'monthly', 'cancel_at_period_end' => true]);

    $result = PlatformBilling::subscribe($tenant->fresh(), 'pro', 'monthly');

    expect($result['action'])->toBe('kept')
        ->and($result['invoice'])->toBeNull()
        ->and($tenant->fresh()->pending_plan)->toBeNull()
        ->and($tenant->fresh()->pending_interval)->toBeNull()
        ->and($tenant->fresh()->cancel_at_period_end)->toBeFalse()
        ->and(PlatformInvoice::query()->count())->toBe(0);
});

it('changes the plan at once when the pro rata difference is too small to charge', function () {
    $tenant = billedTenant('basic', now()->addMinutes(30));

    $result = PlatformBilling::subscribe($tenant, 'pro', 'monthly');

    expect($result['action'])->toBe('upgraded')
        ->and($result['invoice'])->toBeNull()
        ->and($tenant->fresh()->plan)->toBe('pro')
        ->and(PlatformInvoice::query()->count())->toBe(0)
        ->and(audited('platform.plan.upgraded'))->toBe(1);
    Http::assertNothingSent();
});

it('settles an invoice that costs nothing without the bank and never issues a paid one again', function () {
    $tenant = efiTenant();
    $free = PlatformBilling::createInvoice($tenant, PlatformInvoice::TYPE_SUBSCRIPTION, 'basic', 'monthly', now(), now()->addMonth(), [
        ['code' => 'plan', 'description' => 'Plan', 'quantity' => 1, 'unit' => 'monthly', 'amount_cents' => 0],
    ]);

    $issued = PlatformBilling::issue($free);
    expect($issued->status)->toBe(PlatformInvoice::STATUS_PAID)
        ->and($issued->payment_method)->toBe('NONE')
        ->and($issued->amount_paid_cents)->toBe(0)
        ->and($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->fresh()->plan)->toBe('basic');

    expect(PlatformBilling::issue($issued)->is($issued))->toBeTrue()
        ->and(PlatformBilling::reissue($issued)->status)->toBe(PlatformInvoice::STATUS_PAID);
    Http::assertNothingSent();
});

it('keeps why Efí refused a boleto, counts the attempt and lets it be issued again', function () {
    Http::fake([EFI_CHARGES.'/v1/charge/one-step' => Http::sequence()
        ->push(['error' => 'validation_error', 'error_description' => ['property' => '/payment/banking_billet/customer/cpf', 'message' => 'CPF inválido']], 400)
        ->push(['code' => 200, 'data' => ['charge_id' => 2020, 'status' => 'waiting', 'barcode' => '3649', 'pix' => ['qrcode' => '0002']]]),
    ]);
    efiFake();
    $invoice = openInvoice(efiTenant());

    expect(fn () => PlatformBilling::issue($invoice))->toThrow(EfiException::class, '/payment/banking_billet/customer/cpf: CPF inválido');
    $invoice->refresh();
    expect(data_get($invoice->metadata, 'issue_attempts'))->toBe(1)
        ->and(data_get($invoice->metadata, 'issue_error'))->toContain('CPF inválido')
        ->and($invoice->provider_reference)->toBeNull()
        ->and($invoice->status)->toBe(PlatformInvoice::STATUS_OPEN)
        ->and(audited('platform.invoice.issue_failed'))->toBe(1);

    PlatformBilling::issue($invoice);
    $invoice->refresh();
    expect($invoice->provider_reference)->toBe('2020')
        ->and(data_get($invoice->metadata, 'issue_error'))->toBeNull()
        ->and(data_get($invoice->metadata, 'issue_attempts'))->toBeNull();
});

it('keeps why Efí refused a Pix charge, with each violation it lists', function () {
    Http::fake([EFI_PIX.'/v2/cobv/*' => Http::response([
        'nome' => 'valor_invalido', 'mensagem' => 'Requisição inválida.',
        'violacoes' => [['razao' => 'Chave Pix não pertence à conta', 'propriedade' => 'cobv.chave']],
    ], 400)]);
    efiFake();
    $invoice = openInvoice(efiPixTenant());

    expect(fn () => PlatformBilling::issue($invoice))->toThrow(EfiException::class, 'cobv.chave: Chave Pix não pertence à conta');
    expect(data_get($invoice->fresh()->metadata, 'issue_attempts'))->toBe(1)
        ->and(data_get($invoice->fresh()->metadata, 'issue_error'))->toContain('Requisição inválida.')
        ->and($invoice->fresh()->provider_reference)->toBeNull();
});

it('refuses an answer of Efí without the boleto id or without the Pix code', function () {
    Http::fake([
        EFI_CHARGES.'/v1/charge/one-step' => Http::response(['code' => 200, 'data' => ['status' => 'waiting']]),
        EFI_PIX.'/v2/cobv/*' => Http::response(['txid' => 'x', 'status' => 'ATIVA']),
    ]);
    efiFake();

    expect(fn () => PlatformBilling::issue(openInvoice(efiTenant())))->toThrow(EfiException::class, 'Efí did not return the charge id (charge_id).')
        ->and(fn () => PlatformBilling::issue(openInvoice(efiPixTenant())))->toThrow(EfiException::class, 'Efí did not return the Pix code (pixCopiaECola).');
});

it('cancels the charge at the bank and voids the invoice only once', function () {
    efiFake();
    $invoice = PlatformBilling::subscribe(efiTenant(), 'pro', 'monthly')['invoice'];

    PlatformBilling::cancelInvoice($invoice);
    PlatformBilling::cancelInvoice($invoice->fresh());

    $invoice->refresh();
    expect($invoice->status)->toBe(PlatformInvoice::STATUS_VOID)
        ->and(data_get($invoice->metadata, 'void_reason'))->toBe('superseded')
        ->and(Http::recorded(fn (Request $request) => $request->url() === EFI_CHARGES.'/v1/charge/1002/cancel')->count())->toBe(1)
        ->and(audited('platform.invoice.cancelled'))->toBe(1);
});

it('voids the invoice even when the bank fails to cancel its charge, and reports the failure', function () {
    Exceptions::fake();
    Http::fake([EFI_CHARGES.'/v1/charge/*/cancel' => Http::response(['error' => 'server_error', 'error_description' => 'Indisponível'], 500)]);
    efiFake();
    $invoice = PlatformBilling::subscribe(efiTenant(), 'pro', 'monthly')['invoice'];

    PlatformBilling::cancelInvoice($invoice, 'system');

    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_VOID);
    Exceptions::assertReported(fn (EfiException $e) => str_contains($e->getMessage(), 'Indisponível'));
});

it('cancels an invoice without a charge, or one Efí already voided, without calling the bank', function () {
    $tenant = efiTenant();
    $withoutCharge = openInvoice($tenant);
    $voidedAtEfi = openInvoice($tenant, ['provider_reference' => '3003', 'provider_status' => 'canceled']);

    PlatformBilling::cancelInvoice($withoutCharge);
    PlatformBilling::cancelInvoice($voidedAtEfi);

    expect($withoutCharge->fresh()->status)->toBe(PlatformInvoice::STATUS_VOID)
        ->and($voidedAtEfi->fresh()->status)->toBe(PlatformInvoice::STATUS_VOID);
    Http::assertNothingSent();
});

it('leaves an invoice without a charge untouched when asked to check it at the bank', function () {
    $invoice = openInvoice(efiTenant());

    expect(PlatformBilling::sync($invoice)->is($invoice))->toBeTrue()
        ->and($invoice->fresh()->synced_at)->toBeNull();
    Http::assertNothingSent();
});

it('tells the customer once when the boleto details arrive after the charge was created', function () {
    efiFake();
    $invoice = openInvoice(efiTenant(), ['provider_reference' => '1002', 'provider_status' => 'new', 'payment_details' => null]);

    PlatformBilling::sync($invoice);
    PlatformBilling::sync($invoice->fresh());
    PlatformBilling::notifyIssued($invoice->fresh());

    $invoice->refresh();
    expect(PlatformBilling::hasPaymentData($invoice))->toBeTrue()
        ->and(data_get($invoice->metadata, 'notified_at'))->not->toBeNull()
        ->and($invoice->provider_status)->toBe('waiting');
    Queue::assertPushed(PlatformNoticeJob::class, 1);
});

it('flags the tenant when Efí reports the boleto unpaid after the due date', function () {
    efiFake(['status' => 'unpaid']);
    $tenant = billedTenant('pro');
    $invoice = openInvoice($tenant, ['provider_reference' => '1002', 'due_date' => now()->subDays(2)->toDateString()]);

    PlatformBilling::sync($invoice);

    expect($invoice->fresh()->provider_status)->toBe('unpaid')
        ->and($tenant->fresh()->past_due_since)->not->toBeNull();
});

it('flags only open subscription invoices past their due date, and the tenant only once', function () {
    $tenant = billedTenant('pro');
    $upgrade = openInvoice($tenant, ['due_date' => now()->subDays(3)->toDateString()], PlatformInvoice::TYPE_UPGRADE, 'pro');
    $notDue = openInvoice($tenant, ['due_date' => now()->addDay()->toDateString()]);

    PlatformBilling::flagOverdue($upgrade);
    PlatformBilling::flagOverdue($notDue);
    expect($tenant->fresh()->past_due_since)->toBeNull();

    $since = now()->subDays(10)->startOfSecond();
    $tenant->update(['past_due_since' => $since]);
    PlatformBilling::flagOverdue(openInvoice($tenant, ['due_date' => now()->subDays(2)->toDateString()]));
    expect($tenant->fresh()->past_due_since->equalTo($since))->toBeTrue();

    $pending = efiTenant();
    PlatformBilling::flagOverdue(openInvoice($pending, ['due_date' => now()->subDays(2)->toDateString()]));
    expect($pending->fresh()->past_due_since)->toBeNull();
});

it('voids a Pix charge Efí removed', function () {
    efiFake();
    $invoice = PlatformBilling::subscribe(efiPixTenant(), 'pro', 'monthly')['invoice'];

    efiFake([], ['status' => 'REMOVIDA_PELO_PSP']);
    PlatformBilling::sync($invoice);

    $invoice->refresh();
    expect($invoice->status)->toBe(PlatformInvoice::STATUS_VOID)
        ->and(data_get($invoice->metadata, 'void_reason'))->toBe('REMOVIDA_PELO_PSP')
        ->and(audited('platform.invoice.void'))->toBe(1);
});

it('records a Pix payment once, with the time Efí gives or now when it can not be read', function () {
    efiFake();
    $tenant = efiPixTenant();
    $invoice = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];

    efiFake([], ['status' => 'CONCLUIDA', 'pix' => [['valor' => '150.40', 'horario' => 'ontem à tarde']]]);
    PlatformBilling::sync($invoice);
    PlatformBilling::sync($invoice->fresh());
    PlatformBilling::markPaid($invoice->fresh(), 1, 'PIX');

    $invoice->refresh();
    expect($invoice->status)->toBe(PlatformInvoice::STATUS_PAID)
        ->and($invoice->amount_paid_cents)->toBe(15040)
        ->and($invoice->payment_method)->toBe('PIX')
        ->and($invoice->paid_at->diffInMinutes(now()))->toBeLessThan(2)
        ->and(audited('platform.invoice.paid'))->toBe(1)
        ->and($tenant->fresh()->plan)->toBe('pro');

    $second = PlatformBilling::subscribe(efiPixTenant(), 'pro', 'monthly')['invoice'];
    efiFake([], ['status' => 'CONCLUIDA', 'pix' => [['valor' => '149.90', 'horario' => '2026-11-20T10:15:00Z']]]);
    PlatformBilling::sync($second);
    expect($second->fresh()->paid_at->toIso8601String())->toBe('2026-11-20T10:15:00+00:00');

    $third = PlatformBilling::subscribe(efiPixTenant(), 'pro', 'monthly')['invoice'];
    efiFake([], ['status' => 'CONCLUIDA', 'pix' => []]);
    PlatformBilling::sync($third);
    expect($third->fresh()->amount_paid_cents)->toBe($third->amount_cents)
        ->and($third->fresh()->paid_at)->not->toBeNull();
});

it('reactivates a tenant suspended for an overdue invoice as soon as it is paid', function () {
    $tenant = billedTenant('pro');
    $invoice = openInvoice($tenant, ['due_date' => now()->subDays(9)->toDateString()], PlatformInvoice::TYPE_SUBSCRIPTION, 'pro');
    $tenant->update([
        'status' => PlatformTenant::STATUS_SUSPENDED,
        'suspension_reason' => TenantLifecycle::REASON_OVERDUE,
        'suspended_at' => now()->subDays(2),
        'past_due_since' => now()->subDays(9),
    ]);

    PlatformBilling::markPaid($invoice, $invoice->amount_cents, 'BOLETO');

    $tenant->refresh();
    expect($tenant->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->suspension_reason)->toBeNull()
        ->and($tenant->suspended_at)->toBeNull()
        ->and($tenant->past_due_since)->toBeNull();
});

it('keeps a tenant suspended by the operator suspended when an invoice is paid', function () {
    $tenant = billedTenant('pro');
    $invoice = openInvoice($tenant, [], PlatformInvoice::TYPE_SUBSCRIPTION, 'pro');
    $tenant->update(['status' => PlatformTenant::STATUS_SUSPENDED, 'suspension_reason' => TenantLifecycle::REASON_MANUAL, 'suspended_at' => now()]);

    PlatformBilling::markPaid($invoice, $invoice->amount_cents, 'BOLETO');

    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_SUSPENDED)
        ->and($tenant->fresh()->suspension_reason)->toBe(TenantLifecycle::REASON_MANUAL);
});

it('does not run the billing cycle until payments can be taken, nor outside the platform', function () {
    config(['platform.efi.client_id' => null]);
    $tenant = billedTenant('pro', now()->subHour());

    PlatformBilling::runCycle();
    (new PlatformBillingCycleJob)->handle();
    expect(PlatformInvoice::query()->count())->toBe(0);

    config(['platform.efi.client_id' => 'Client_Id_test', 'platform.enabled' => false]);
    (new PlatformBillingCycleJob)->handle();
    expect(PlatformInvoice::query()->count())->toBe(0);

    efiFake();
    config(['platform.enabled' => true]);
    (new PlatformBillingCycleJob)->handle();
    expect(PlatformInvoice::query()->where('team_id', $tenant->team_id)->count())->toBe(1);
});

it('keeps renewing the other tenants when the renewal of one fails', function () {
    Exceptions::fake();
    efiFake();
    $failing = billedTenant('pro', now()->subHour());
    $renewed = billedTenant('basic', now()->subHour());
    PlatformInvoice::creating(function (PlatformInvoice $invoice) use ($failing) {
        if ($invoice->team_id === $failing->team_id) {
            throw new \RuntimeException('database unavailable');
        }
    });

    expect(PlatformBilling::renewDue())->toBe(1)
        ->and(PlatformInvoice::query()->where('team_id', $renewed->team_id)->count())->toBe(1)
        ->and(PlatformInvoice::query()->where('team_id', $failing->team_id)->count())->toBe(0);
    Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'database unavailable');
});

it('renews on the current plan when the scheduled one has no price, and skips a plan without a price', function () {
    efiFake();
    $tenant = billedTenant('pro', now()->subHour());
    $tenant->update(['pending_plan' => 'enterprise', 'pending_interval' => 'monthly']);

    $invoice = PlatformBilling::renew($tenant->fresh());
    expect($invoice->plan)->toBe('pro')
        ->and($invoice->amount_cents)->toBe(14990);

    config(['platform.plans.basic.price_monthly' => null]);
    $free = billedTenant('basic', now()->subHour());
    expect(PlatformBilling::renew($free))->toBeNull()
        ->and(PlatformInvoice::query()->where('team_id', $free->team_id)->count())->toBe(0)
        ->and(audited('platform.invoice.renewal_skipped'))->toBe(1);
});

it('adds the usage above the plan to the renewal invoice', function () {
    efiFake();
    config(['platform.overage.egress_gb_cents' => 50, 'platform.plans.basic.quotas.egress_gb' => 10]);
    $tenant = billedTenant('basic', now()->subHour());
    AggregatedUsageHourly::query()->create([
        'team_id' => $tenant->team_id, 'metric' => UsageEvent::METRIC_EGRESS_BYTES, 'value' => 25e9, 'samples' => 1,
        'period_start' => now()->subDays(3)->startOfHour(), 'period_end' => now()->subDays(3)->startOfHour()->addHour(),
    ]);

    $invoice = PlatformBilling::renew($tenant);

    $egress = collect($invoice->lines)->firstWhere('code', 'egress_overage');
    expect($egress['quantity'])->toBe(15)
        ->and($egress['amount_cents'])->toBe(750)
        ->and($invoice->amount_cents)->toBe(4990 + 750)
        ->and(collect($invoice->lines)->pluck('code')->all())->not->toContain('cpu_hours');
});

it('keeps the renewal invoice when Efí is unavailable, to issue it later', function () {
    Exceptions::fake();
    Http::fake([EFI_CHARGES.'/v1/charge/one-step' => Http::response(['error' => 'server_error', 'error_description' => 'Fora do ar'], 503)]);
    efiFake();
    $tenant = billedTenant('pro', now()->subHour());

    $invoice = PlatformBilling::renew($tenant);

    expect($invoice)->not->toBeNull()
        ->and($invoice->fresh()->provider_reference)->toBeNull()
        ->and(data_get($invoice->fresh()->metadata, 'issue_error'))->toContain('Fora do ar')
        ->and(PlatformBilling::renew($tenant->fresh()))->toBeNull();
    Exceptions::assertReported(EfiException::class);
});

it('cancels overdue upgrade invoices instead of flagging the tenant', function () {
    $tenant = billedTenant('basic');
    $upgrade = openInvoice($tenant, ['due_date' => now()->subDays(2)->toDateString()], PlatformInvoice::TYPE_UPGRADE, 'pro');

    expect(PlatformBilling::markOverdue())->toBe(0);

    expect($upgrade->fresh()->status)->toBe(PlatformInvoice::STATUS_VOID)
        ->and($tenant->fresh()->past_due_since)->toBeNull()
        ->and($tenant->fresh()->plan)->toBe('basic');
});

it('reconciles open invoices: retries charges that failed, checks stale ones and leaves manual ones alone', function () {
    Exceptions::fake();
    Http::fake([EFI_CHARGES.'/v1/charge/one-step' => Http::response(['error' => 'server_error', 'error_description' => 'Fora do ar'], 503)]);
    efiFake();
    $tenant = efiTenant();
    $manual = openInvoice($tenant, ['metadata' => ['manual' => true]]);
    $failed = openInvoice($tenant);
    $exhausted = openInvoice($tenant, ['metadata' => ['issue_attempts' => 5]]);
    $stale = openInvoice($tenant, ['provider_reference' => '1002', 'synced_at' => now()->subHour()]);
    $fresh = openInvoice($tenant, ['provider_reference' => '1003', 'synced_at' => now()->subMinutes(5)]);

    expect(PlatformBilling::reconcileOpen())->toBe(1);

    Queue::assertPushed(PlatformSyncInvoiceJob::class, fn ($job) => $job->invoiceId === $stale->id);
    Queue::assertNotPushed(PlatformSyncInvoiceJob::class, fn ($job) => in_array($job->invoiceId, [$fresh->id, $manual->id], true));
    expect(data_get($failed->fresh()->metadata, 'issue_attempts'))->toBe(1)
        ->and(data_get($exhausted->fresh()->metadata, 'issue_attempts'))->toBe(5)
        ->and(data_get($manual->fresh()->metadata, 'issue_attempts'))->toBeNull()
        ->and(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v1/charge/one-step'))->count())->toBe(1);
    Exceptions::assertReported(EfiException::class);
});

it('simulates a Pix payment in the sandbox by recording it', function () {
    efiFake();
    $invoice = PlatformBilling::subscribe(efiPixTenant(), 'pro', 'monthly')['invoice'];

    PlatformBilling::simulatePayment($invoice);

    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_PAID)
        ->and($invoice->fresh()->payment_method)->toBe('SIMULATED');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/settle'));
});

it('checks an invoice at the bank again until its payment data arrives, then stops', function () {
    efiFake(['payment' => null, 'pix' => null]);
    $invoice = openInvoice(efiTenant(), ['provider_reference' => '1002']);

    (new PlatformSyncInvoiceJob($invoice->id))->handle();
    Queue::assertPushed(PlatformSyncInvoiceJob::class, fn ($job) => $job->invoiceId === $invoice->id && $job->attempt === 1);

    (new PlatformSyncInvoiceJob($invoice->id, 12))->handle();
    Queue::assertPushed(PlatformSyncInvoiceJob::class, 1);

    expect((new PlatformSyncInvoiceJob($invoice->id))->backoff())->toBe([10, 30, 60]);
});

it('does not check invoices that are gone, of another provider or without Efí credentials', function () {
    $invoice = openInvoice(efiTenant(), ['provider_reference' => '1002']);

    (new PlatformSyncInvoiceJob(999999))->handle();
    $invoice->forceFill(['provider' => 'stripe'])->save();
    (new PlatformSyncInvoiceJob($invoice->id))->handle();
    $invoice->forceFill(['provider' => PlatformBilling::PROVIDER])->save();
    config(['platform.efi.client_secret' => null]);
    (new PlatformSyncInvoiceJob($invoice->id))->handle();

    Http::assertNothingSent();
    expect($invoice->fresh()->synced_at)->toBeNull();
});

it('e-mails the notices of a team to its owners and admins only, and reports a failed delivery', function () {
    Exceptions::fake();
    InstanceSettings::query()->find(0)->forceFill(['smtp_enabled' => true, 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_from_address' => 'nao-responda@example.com', 'smtp_from_name' => 'Hostrema'])->save();
    $tenant = efiTenant();
    $team = Team::find($tenant->team_id);
    $owner = $team->members()->first();
    foreach (['admin@example.com' => 'admin', 'membro@example.com' => 'member', 'falha@example.com' => 'admin'] as $email => $role) {
        $team->members()->attach(User::factory()->create(['email' => $email])->id, ['role' => $role]);
    }
    $transport = new class extends \Illuminate\Mail\Transport\ArrayTransport
    {
        public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
        {
            if (str_contains($message->toString(), 'falha@example.com')) {
                throw new \RuntimeException('mailbox unavailable');
            }

            return parent::send($message, $envelope);
        }
    };
    app('mail.manager')->extend('smtp', fn () => $transport);
    app('mail.manager')->purge('smtp');

    (new PlatformNoticeJob($team, 'Fatura emitida', ['Sua fatura HR0001 vence amanhã.'], 'https://painel.example.com/subscription', 'Pagar agora'))->handle();

    $sent = $transport->messages()->mapWithKeys(fn ($message) => [
        $message->getOriginalMessage()->getTo()[0]->getAddress() => $message->getOriginalMessage(),
    ]);
    expect($sent->keys()->all())->toEqualCanonicalizing([$owner->email, 'admin@example.com'])
        ->and($sent['admin@example.com']->getSubject())->toBe('[Hostrema] Fatura emitida')
        ->and($sent['admin@example.com']->getHtmlBody())->toContain('Sua fatura HR0001 vence amanhã.')
        ->and($sent['admin@example.com']->getHtmlBody())->toContain('https://painel.example.com/subscription')
        ->and($sent['admin@example.com']->getHtmlBody())->toContain('Pagar agora');
    Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'mailbox unavailable');
});

it('turns the usage of an invoice into a CSV with the lines and the hourly usage', function () {
    $tenant = efiTenant();
    $invoice = openInvoice($tenant, ['number' => 'HR00000042', 'metadata' => ['usage_from' => '2026-10-01T00:00:00+00:00', 'usage_to' => '2026-11-01T00:00:00+00:00']]);
    AggregatedUsageHourly::query()->create(['team_id' => $tenant->team_id, 'metric' => 'cpu_seconds', 'value' => 3600, 'samples' => 60,
        'period_start' => '2026-10-15 10:00:00', 'period_end' => '2026-10-15 11:00:00']);
    AggregatedUsageHourly::query()->create(['team_id' => $tenant->team_id, 'metric' => 'cpu_seconds', 'value' => 99, 'samples' => 1,
        'period_start' => '2026-11-02 10:00:00', 'period_end' => '2026-11-02 11:00:00']);

    ob_start();
    InvoiceUsageExport::write($invoice);
    $csv = ob_get_clean();

    expect($csv)->toContain('invoice,HR00000042,status,open')
        ->and($csv)->toContain('usage_window,2026-10-01T00:00:00+00:00,2026-11-01T00:00:00+00:00')
        ->and($csv)->toContain('code,description,quantity,unit,amount_brl')
        ->and($csv)->toContain('plan,')
        ->and($csv)->toContain('total,,,,49.90')
        ->and($csv)->toContain('2026-10-15T10:00:00+00:00,cpu_seconds,3600,60')
        ->and($csv)->not->toContain('2026-11-02T10:00:00');

    $withoutWindow = openInvoice($tenant);
    [$from, $to] = InvoiceUsageExport::window($withoutWindow);
    expect($from->equalTo($withoutWindow->period_start))->toBeTrue()
        ->and($to->equalTo($withoutWindow->period_end))->toBeTrue();
});

it('answers 404 to Efí notifications with a wrong token, and changes nothing', function () {
    $invoice = openInvoice(efiTenant(), ['provider_reference' => '1002']);

    $this->postJson('/api/v1/billing/webhooks/efi/tokenErrado1234567890abcd/pix', ['pix' => [['txid' => $invoice->provider_reference, 'valor' => '149.90']]])->assertNotFound();
    $this->postJson('/api/v1/billing/webhooks/efi/tokenErrado1234567890abcd/charges', ['notification' => 'abc'])->assertNotFound();
    $this->postJson('/api/v1/billing/webhooks/efi/curto/charges', ['notification' => 'abc'])->assertNotFound();

    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_OPEN);
    Http::assertNothingSent();
});

it('sends DELETE requests and refuses unknown HTTP methods in the Efí client', function () {
    Http::fake([EFI_CHARGES.'/v1/authorize' => Http::response(['access_token' => 't', 'expires_in' => 600]), EFI_CHARGES.'/v1/thing' => Http::response(['ok' => true])]);
    $client = new EfiClient;
    $request = (new ReflectionClass($client))->getMethod('request');

    expect($request->invoke($client, EfiClient::API_CHARGES, 'DELETE', '/v1/thing')->json('ok'))->toBeTrue()
        ->and(fn () => $request->invoke($client, EfiClient::API_CHARGES, 'TRACE', '/v1/thing'))->toThrow(\InvalidArgumentException::class, 'Unsupported method TRACE');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === EFI_CHARGES.'/v1/thing');
});

it('stores a credential for the environment in use and refuses keys that are not credentials', function () {
    EfiSettings::store('environment', 'production');
    EfiSettings::store('client_id', 'Client_Id_em_uso');

    expect(EfiSettings::credential('production', 'client_id'))->toBe('Client_Id_em_uso')
        ->and(EfiSettings::credential('sandbox', 'client_id'))->toBe('Client_Id_test')
        ->and(fn () => EfiSettings::storeCredential('sandbox', 'webhook_token', 'x'))->toThrow(\InvalidArgumentException::class)
        ->and(EfiSettings::normalize('qualquer'))->toBe('production');
});

it('reads a certificate saved as a file path and ignores a file without a certificate', function () {
    $certificate = efiCertificate();
    $directory = storage_path('app/private/platform/efi-test');
    @mkdir($directory, 0700, true);
    file_put_contents($directory.'/cert.pem', $certificate['certificate']);
    file_put_contents($directory.'/not-a-cert.txt', 'hello');

    config(['platform.efi.certificate' => $directory.'/cert.pem', 'platform.efi.private_key' => $directory.'/not-a-cert.txt']);
    expect(EfiSettings::pem('certificate'))->toBe(trim($certificate['certificate'])."\n")
        ->and(EfiSettings::pem('private_key'))->toBeNull();

    config(['platform.efi.certificate' => base64_encode($certificate['certificate'])]);
    expect(EfiSettings::pem('certificate'))->toBe(trim($certificate['certificate'])."\n");

    config(['platform.efi.certificate' => 'garbage']);
    expect(EfiSettings::pem('certificate'))->toBeNull();

    \Illuminate\Support\Facades\File::deleteDirectory($directory);
});

it('refuses a PEM whose encrypted private key does not open with the password given', function () {
    $certificate = efiCertificate();
    openssl_pkey_export(openssl_pkey_get_private($certificate['key']), $encrypted, 'segredo');

    expect(fn () => EfiSettings::readCertificate($certificate['certificate'].$encrypted, 'errada'))
        ->toThrow(EfiException::class, 'The private key could not be read. Check the password of the certificate.');
    expect(EfiSettings::readCertificate($certificate['certificate'].$encrypted, 'segredo')[1])->toContain('PRIVATE KEY');
});

it('refuses a legacy .p12 that has the certificate but not the key', function () {
    $openssl = (new \Symfony\Component\Process\ExecutableFinder)->find('openssl');
    if (is_null($openssl)) {
        $this->markTestSkipped('openssl command not available');
    }
    $directory = sys_get_temp_dir().'/efi-nokey-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/cert.pem', efiCertificate()['certificate']);
    (new \Symfony\Component\Process\Process([$openssl, 'pkcs12', '-export', '-legacy', '-nokeys', '-in', $directory.'/cert.pem', '-out', $directory.'/cert.p12', '-passout', 'pass:']))->mustRun();

    expect(fn () => EfiSettings::readCertificate((string) file_get_contents($directory.'/cert.p12')))
        ->toThrow(EfiException::class, 'The .p12 certificate could not be read.');

    \Illuminate\Support\Facades\File::deleteDirectory($directory);
});

it('keeps the operator console working when an action on an invoice fails', function () {
    efiOperator();
    $invoice = openInvoice(efiTenant(), ['provider_reference' => '1002']);
    Http::fake([EFI_CHARGES.'/v1/authorize' => Http::response(['error' => 'invalid_client', 'error_description' => 'Credenciais inválidas'], 401)]);
    efiOperator();

    Livewire::test(AdminBilling::class)
        ->call('syncInvoice', $invoice->uuid)
        ->assertDispatched('error', fn (string $event, array $params) => $params[0] === "Invoice {$invoice->number} could not be checked at the bank" && str_contains($params[1], 'Credenciais inválidas'))
        ->call('reissue', $invoice->uuid)
        ->assertDispatched('error', fn (string $event, array $params) => $params[0] === "Invoice {$invoice->number} could not be issued again")
        ->call('simulatePayment', $invoice->uuid)
        ->assertDispatched('error', fn (string $event, array $params) => $params[0] === 'The payment could not be simulated')
        ->assertNotDispatched('success');
});

it('lets the operator check, cancel, re-issue and simulate invoices and run the cycle', function () {
    efiFake();
    $tenant = efiTenant();
    $team = Team::find($tenant->team_id);
    $invoice = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];
    efiOperator();

    $component = Livewire::test(AdminBilling::class)
        ->set('search', $invoice->number)
        ->assertSee($invoice->number)
        ->set('search', $team->name)
        ->assertSee($invoice->number)
        ->set('search', 'ninguém-tem-este-nome')
        ->assertDontSee($invoice->number)
        ->set('search', '')
        ->call('syncInvoice', $invoice->uuid)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === "Invoice {$invoice->number} checked at the bank: Open.")
        ->call('reissue', $invoice->uuid)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === "A new charge of invoice {$invoice->number} was issued.");
    expect($invoice->fresh()->provider_reference)->toBe('1002')
        ->and($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_OPEN)
        ->and(Http::recorded(fn (Request $request) => $request->url() === EFI_CHARGES.'/v1/charge/one-step')->count())->toBe(2)
        ->and(Http::recorded(fn (Request $request) => $request->url() === EFI_CHARGES.'/v1/charge/1002/cancel')->count())->toBe(1);

    $component->call('cancelInvoice', $invoice->uuid)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === "Invoice {$invoice->number} cancelled.");
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_VOID);

    $this->travel(2)->seconds();
    $other = PlatformBilling::subscribe($tenant->fresh(), 'pro', 'monthly')['invoice'];
    efiFake(['status' => 'settled']);
    $component->call('simulatePayment', $other->uuid)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Payment simulated in the sandbox.')
        ->call('markPaid', $invoice->uuid)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === "Invoice {$invoice->number} marked as paid.")
        ->call('runCycle')
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Billing cycle executed.');
    expect($other->fresh()->status)->toBe(PlatformInvoice::STATUS_PAID);

    $component->call('markPaid', 'nao-existe')->assertNotFound();
});

it('says which payment method Efí refused while the other works, and why', function () {
    efiOperator();
    Http::fake([EFI_PIX.'/oauth/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Credenciais recusadas'], 401)]);
    efiFake();
    $certificate = efiCertificate();
    config(['platform.efi.pix_key' => 'pix@hostrema.com.br', 'platform.efi.certificate' => $certificate['certificate'], 'platform.efi.private_key' => $certificate['key']]);

    Livewire::test(AdminBilling::class)
        ->call('connect')
        ->assertDispatched('warning', fn (string $event, array $params) => $params[0] === 'Efí connected in part (Homologação)')
        ->assertSee('Efí connected in part (Homologação)')
        ->assertSee('Boleto: Connected')
        ->assertSee('Pix: Error')
        ->assertSee('Connected: invoices can be charged by boleto.')
        ->assertSee('Efí refused the Client ID and the Client Secret on the Pix API (HTTP 401).')
        ->assertSee('Credenciais recusadas')
        ->assertSee('Payment notices wait for the Pix connection.');
});

it('reports a failure while recording the connection instead of breaking the console', function () {
    efiFake();
    efiOperator();
    $certificate = efiCertificate();
    config(['platform.efi.pix_key' => 'pix@hostrema.com.br', 'platform.efi.certificate' => $certificate['certificate'], 'platform.efi.private_key' => $certificate['key']]);
    \App\Models\PlatformSetting::saving(function (\App\Models\PlatformSetting $setting) {
        if ($setting->key === 'efi.webhook_registered_at') {
            throw new \RuntimeException('could not save the webhook date');
        }
    });

    Livewire::test(AdminBilling::class)
        ->call('connect')
        ->assertDispatched('error', fn (string $event, array $params) => $params[0] === 'The connection test could not run' && $params[1] === 'could not save the webhook date');
});

it('shows a customer with a monthly or yearly plan the interval of the plan', function () {
    $tenant = billedTenant('pro');
    $tenant->update(['billing_interval' => 'yearly']);

    Livewire::test(Pricing::class)->assertSet('interval', 'yearly');
});

it('refuses unknown plans, plans sold by contact and checkouts before payments are ready', function () {
    efiFake();
    efiTenant();

    Livewire::test(Pricing::class)
        ->call('subscribe', 'nao-existe')
        ->assertDispatched('error', 'Unknown plan.')
        ->call('subscribe', 'enterprise')
        ->assertDispatched('error', 'This plan is not available for self-service checkout.');

    config(['platform.efi.client_id' => null]);
    Livewire::test(Pricing::class)
        ->call('subscribe', 'pro')
        ->assertDispatched('error', 'Payments are not available yet. Please contact support.');
    expect(PlatformInvoice::query()->count())->toBe(0);
});

it('sends a customer who keeps or changes the plan to the subscription page', function () {
    $tenant = billedTenant('pro');

    Livewire::test(Pricing::class)
        ->call('subscribe', 'pro')
        ->assertRedirect(route('subscription.show'));
    expect($tenant->fresh()->plan)->toBe('pro');
});

it('shows the error of a checkout the bank refused', function () {
    Http::fake([EFI_CHARGES.'/v1/charge/one-step' => Http::response(['error' => 'validation_error', 'error_description' => 'Pagador inválido'], 400)]);
    efiFake();
    efiTenant();

    Livewire::test(Pricing::class)
        ->call('subscribe', 'pro')
        ->assertDispatched('error');
    expect(data_get(PlatformInvoice::query()->first()->metadata, 'issue_error'))->toContain('Pagador inválido');
});

it('lets only owners and admins cancel a checkout or change billing', function () {
    efiFake();
    $tenant = efiTenant();
    $owner = auth()->user();
    $invoice = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];

    teamMember($tenant);
    Livewire::test(Pricing::class)
        ->call('cancelCheckout', $invoice->uuid)
        ->assertDispatched('error', 'Only team owners and admins can manage the subscription.')
        ->call('saveProfile')
        ->call('reissueInvoice', $invoice->uuid);
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_OPEN)
        ->and(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/cancel'))->count())->toBe(0);

    $this->actingAs($owner);
    session(['currentTeam' => Team::find($tenant->team_id)]);
    Livewire::test(Pricing::class)->call('cancelCheckout', $invoice->uuid);
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_VOID);
});

it('lets the customer cancel and resume the subscription and drop a scheduled change', function () {
    $tenant = billedTenant('pro');
    $tenant->update(['pending_plan' => 'basic', 'pending_interval' => 'monthly']);

    Livewire::test(Billing::class)
        ->call('cancelSubscription')
        ->assertDispatched('success');
    expect($tenant->fresh()->cancel_at_period_end)->toBeTrue();

    Livewire::test(Billing::class)
        ->call('resumeSubscription')
        ->assertDispatched('success', 'The subscription will be renewed.')
        ->call('cancelPendingChange');
    expect($tenant->fresh()->cancel_at_period_end)->toBeFalse()
        ->and($tenant->fresh()->pending_plan)->toBeNull();

    teamMember($tenant);
    Livewire::test(Billing::class)->call('cancelSubscription')->assertDispatched('error');
    expect($tenant->fresh()->cancel_at_period_end)->toBeFalse();
});

it('sends the operator away from the customer billing page', function () {
    efiOperator();

    Livewire::test(Billing::class)->assertRedirect(route('dashboard'));
});

it('retries the charges of open invoices once the billing details are fixed', function () {
    efiFake();
    $tenant = efiTenant();
    $failed = openInvoice($tenant, ['metadata' => ['issue_attempts' => 5, 'issue_error' => 'CPF inválido']]);

    Livewire::test(Pricing::class)
        ->set('profile.document', '52998224725')
        ->call('saveProfile');

    expect(data_get($failed->fresh()->metadata, 'issue_attempts'))->toBeNull();
});

it('ignores payment methods that are not offered', function () {
    efiFake();
    $tenant = efiTenant();

    Livewire::test(Pricing::class)->call('payWith', 'bitcoin');

    expect(data_get($tenant->fresh()->billing_profile, 'payment_method'))->toBeNull();
});

it('checks an invoice at the bank when the customer asks, a few times a minute at most', function () {
    efiFake();
    $tenant = efiTenant();
    $invoice = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];
    RateLimiter::clear('platform-invoice-refresh:'.$invoice->id);

    $component = Livewire::test(Pricing::class);
    foreach (range(1, 8) as $ignored) {
        $component->call('refreshInvoice', $invoice->uuid);
    }

    expect(Http::recorded(fn (Request $request) => $request->method() === 'GET' && $request->url() === EFI_CHARGES.'/v1/charge/1002')->count())->toBe(6);
    $component->call('refreshInvoice', 'nao-existe')->assertDispatched('error');
});

it('does not poll invoices that are paid, manual or without a charge, and survives a bank failure', function () {
    Exceptions::fake();
    $tenant = efiTenant();
    $manual = openInvoice($tenant, ['metadata' => ['manual' => true]]);
    $broken = openInvoice($tenant, ['provider_reference' => '1002']);
    Http::fake([EFI_CHARGES.'/*' => Http::response(['error' => 'server_error'], 500)]);

    Livewire::test(Pricing::class)
        ->call('checkPayment', $manual->uuid)
        ->call('checkPayment', $broken->uuid)
        ->call('checkPayment', 'nao-existe');

    Exceptions::assertReported(EfiException::class);
    expect($broken->fresh()->status)->toBe(PlatformInvoice::STATUS_OPEN);
});

it('opens the boleto only for invoices that have one', function () {
    efiFake();
    $tenant = efiTenant();
    $boleto = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];
    $pix = openInvoice($tenant, ['provider_reference' => 'txid123', 'metadata' => ['rail' => 'pix'], 'payment_details' => ['boleto_url' => 'https://x']]);

    Livewire::test(Pricing::class)
        ->call('downloadBoleto', $boleto->uuid)
        ->assertRedirect(data_get($boleto->payment_details, 'boleto_url'))
        ->call('downloadBoleto', $pix->uuid)
        ->assertDispatched('error', 'The boleto is not available yet.');

    Livewire::test(Pricing::class)->call('downloadBoleto', 'nao-existe')->assertDispatched('error');
});

it('lets the customer download the usage of an invoice as CSV', function () {
    $tenant = efiTenant();
    $invoice = openInvoice($tenant, ['number' => 'HR00000077']);

    Livewire::test(Pricing::class)
        ->call('downloadUsage', $invoice->uuid)
        ->assertFileDownloaded('uso-HR00000077.csv');
});

it('simulates payments for customers only where the sandbox or the manual provider allows it', function () {
    efiFake();
    $tenant = efiTenant();
    $boleto = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];
    $manual = openInvoice($tenant, ['metadata' => ['manual' => true]]);

    // Only where the operator allows it (tests and the local environment).
    Livewire::test(Pricing::class)->call('simulatePayment', $manual->uuid)->assertNotDispatched('success');
    expect($manual->fresh()->status)->toBe(PlatformInvoice::STATUS_OPEN);
    config(['platform.billing.allow_simulated_payments' => true]);

    Livewire::test(Pricing::class)->call('simulatePayment', $manual->uuid)->assertDispatched('success', 'Payment simulated.');
    expect($manual->fresh()->status)->toBe(PlatformInvoice::STATUS_PAID);

    efiFake(['status' => 'settled']);
    Livewire::test(Pricing::class)->call('simulatePayment', $boleto->uuid)->assertDispatched('success');
    expect($boleto->fresh()->status)->toBe(PlatformInvoice::STATUS_PAID);

    config(['platform.efi.environment' => 'production']);
    $live = openInvoice($tenant, ['provider_reference' => '4004']);
    Livewire::test(Pricing::class)->call('simulatePayment', $live->uuid)
        ->assertDispatched('error', 'Payments can only be simulated with the manual provider or the Efí sandbox.');
    Livewire::test(Pricing::class)->call('simulatePayment', 'nao-existe')->assertDispatched('error');
    expect($live->fresh()->status)->toBe(PlatformInvoice::STATUS_OPEN);
});

it('lets the customer ask for a new charge of an open invoice', function () {
    efiFake();
    $tenant = efiTenant();
    $invoice = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];

    Livewire::test(Pricing::class)
        ->call('reissueInvoice', $invoice->uuid)
        ->assertDispatched('success', 'A new charge was issued.')
        ->call('reissueInvoice', 'nao-existe')
        ->assertDispatched('error');
});

it('finds no invoice for ids that are not uuids instead of failing the query', function () {
    efiFake();
    $tenant = efiTenant();
    $invoice = PlatformBilling::subscribe($tenant, 'pro', 'monthly')['invoice'];

    Livewire::test(Pricing::class)
        ->set('watchedInvoice', "x' OR 1=1 --")
        ->assertOk()
        ->set('watchedInvoice', $invoice->uuid)
        ->assertOk();

    expect(PlatformInvoice::query()->withUuid('nao-e-uuid')->exists())->toBeFalse()
        ->and(PlatformInvoice::query()->withUuid(null)->exists())->toBeFalse()
        ->and(PlatformInvoice::query()->withUuid($invoice->uuid)->first()->is($invoice))->toBeTrue();

    efiOperator();
    Livewire::test(AdminBilling::class)->call('markPaid', 'nao-e-uuid')->assertNotFound();
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_OPEN);
});
