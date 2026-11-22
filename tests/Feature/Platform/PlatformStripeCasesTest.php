<?php

use App\Actions\Database\StartDatabase;
use App\Actions\Platform\ProvisionTenant;
use App\Jobs\PlatformNoticeJob;
use App\Livewire\Platform\Pricing;
use App\Models\AggregatedUsageHourly;
use App\Models\AuditLog;
use App\Models\InstanceSettings;
use App\Models\PlatformInvoice;
use App\Models\PlatformTenant;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\StripeBilling;
use App\Services\Platform\TenantLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.locale' => 'en',
        'platform.billing.provider' => 'stripe',
        'platform.trial_days' => 0,
        'platform.currency' => 'brl',
        'platform.overage.egress_gb_cents' => 50,
        'platform.overage.storage_gb_month_cents' => 100,
        'platform.plans.pro.stripe_price_monthly' => 'price_pro_m',
        'platform.plans.pro.stripe_price_yearly' => 'price_pro_y',
        'subscription.stripe_api_key' => 'sk_test_hostrema',
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.com.br']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

/**
 * Stripe API stand-in: answers each path with the given object (404 otherwise) and records the requests.
 *
 * @param  array<string, array<string, mixed>|Closure>  $answers  path pattern => object, or a closure of the params
 */
function stripeFake(array $answers = []): object
{
    $fake = new class($answers) implements ClientInterface
    {
        /** @var list<array{method: string, path: string, params: array<string, mixed>, headers: array<int, string>}> */
        public array $requests = [];

        public function __construct(private array $answers) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
        {
            $path = (string) parse_url($absUrl, PHP_URL_PATH);
            $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params, 'headers' => $headers];
            foreach ($this->answers as $pattern => $answer) {
                if (Str::is($pattern, $path)) {
                    return [json_encode($answer instanceof Closure ? $answer($params) : $answer), 200, []];
                }
            }

            return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => "No such route: {$path}"]]), 404, []];
        }

        public function header(int $request, string $name): ?string
        {
            foreach ($this->requests[$request]['headers'] as $header) {
                if (str_starts_with(strtolower($header), strtolower($name).':')) {
                    return trim(substr($header, strlen($name) + 1));
                }
            }

            return null;
        }
    };
    ApiRequestor::setHttpClient($fake);

    return $fake;
}

function stripeTenant(array $attributes = [], ?string $customer = 'cus_hostrema'): PlatformTenant
{
    $team = Team::factory()->create();
    if ($customer) {
        Subscription::create(['team_id' => $team->id, 'stripe_customer_id' => $customer.$team->id, 'stripe_invoice_paid' => true]);
    }
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(array_merge(['plan' => 'pro', 'status' => PlatformTenant::STATUS_ACTIVE, 'provisioned_at' => now()->subMonth()], $attributes));

    return $tenant->fresh();
}

/**
 * 300 GB of egress (50 GB above the plan) and 73 hours with 10 GB of storage above the plan.
 */
function stripeOverage(PlatformTenant $tenant, \Carbon\CarbonInterface $from): void
{
    foreach (range(0, 72) as $hour) {
        $start = $from->copy()->addHours($hour + 1);
        AggregatedUsageHourly::query()->create(['team_id' => $tenant->team_id, 'metric' => 'storage_bytes', 'value' => 60e9, 'samples' => 12, 'period_start' => $start, 'period_end' => $start->copy()->addHour()]);
    }
    AggregatedUsageHourly::query()->create(['team_id' => $tenant->team_id, 'metric' => 'egress_bytes', 'value' => 300e9, 'samples' => 12, 'period_start' => $from->copy()->addDays(5), 'period_end' => $from->copy()->addDays(5)->addHour()]);
}

it('opens a Stripe checkout of a self-service plan, with the trial for new customers', function () {
    $stripe = stripeFake(['/v1/checkout/sessions' => ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']]);
    config(['platform.trial_days' => 14]);
    $user = User::factory()->create();
    $team = $user->teams()->first();

    expect(StripeBilling::checkoutUrl($team, $user, 'pro', 'monthly'))->toBe('https://checkout.stripe.com/c/pay/cs_1');

    $params = $stripe->requests[0]['params'];
    expect($stripe->requests[0])->method->toBe('post')->path->toBe('/v1/checkout/sessions')
        ->and($params['mode'])->toBe('subscription')
        ->and($params['client_reference_id'])->toBe("{$user->id}:{$team->id}")
        ->and($params['line_items'][0])->toMatchArray(['price' => 'price_pro_m', 'quantity' => 1])
        ->and($params['subscription_data']['metadata'])->toMatchArray(['team_id' => $team->id, 'user_id' => $user->id, 'platform_plan' => 'pro', 'platform_interval' => 'monthly'])
        ->and($params['subscription_data']['trial_period_days'])->toBe(14)
        ->and($params['customer_email'])->toBe($user->email)
        ->and($params)->not->toHaveKey('customer')
        ->and($params['success_url'])->toContain('success=1')
        ->and($params['cancel_url'])->toContain('cancelled=1');
});

it('reuses the Stripe customer of the team, without a new trial', function () {
    $stripe = stripeFake(['/v1/checkout/sessions' => ['id' => 'cs_2', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_2']]);
    config(['platform.trial_days' => 14]);
    $user = User::factory()->create();
    $team = $user->teams()->first();
    Subscription::create(['team_id' => $team->id, 'stripe_customer_id' => 'cus_known']);

    StripeBilling::checkoutUrl($team->fresh(), $user, 'pro', 'yearly');

    $params = $stripe->requests[0]['params'];
    expect($params['customer'])->toBe('cus_known')
        ->and($params['customer_update'])->toBe(['name' => 'auto', 'address' => 'auto'])
        ->and($params['line_items'][0]['price'])->toBe('price_pro_y')
        ->and($params['subscription_data'])->not->toHaveKey('trial_period_days')
        ->and($params)->not->toHaveKey('customer_email');
});

it('refuses a checkout of plans that are not self-service or have no Stripe price', function () {
    $stripe = stripeFake();
    $user = User::factory()->create();
    $team = $user->teams()->first();
    config(['platform.plans.pro.stripe_price_yearly' => null]);

    expect(fn () => StripeBilling::checkoutUrl($team, $user, 'enterprise', 'monthly'))->toThrow(RuntimeException::class, 'This plan is not available for self-service checkout.')
        ->and(fn () => StripeBilling::checkoutUrl($team, $user, 'pro', 'yearly'))->toThrow(RuntimeException::class, 'This plan is not available for self-service checkout.')
        ->and($stripe->requests)->toBe([]);
});

it('sends the customer from the pricing page to the Stripe checkout, and explains a Stripe failure', function () {
    stripeFake(['/v1/checkout/sessions' => ['id' => 'cs_3', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_3']]);
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(Pricing::class)->call('subscribe', 'pro')->assertRedirect('https://checkout.stripe.com/c/pay/cs_3');

    stripeFake();
    Livewire::test(Pricing::class)->call('subscribe', 'pro')->assertNoRedirect()
        ->assertDispatched('error', fn (string $event, array $params) => str_contains((string) ($params[0] ?? ''), 'No such route: /v1/checkout/sessions'));
});

it('handles only the Stripe events of the platform, and reports the ones that fail', function () {
    Exceptions::fake();
    $stripe = stripeFake();
    $tenant = stripeTenant();
    $customer = 'cus_hostrema'.$tenant->team_id;

    config(['platform.enabled' => false]);
    StripeBilling::handle('customer.subscription.deleted', ['customer' => $customer]);
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_ACTIVE);

    config(['platform.enabled' => true]);
    StripeBilling::handle('customer.created', ['id' => $customer]);
    StripeBilling::handle('invoice.payment_failed', ['customer' => $customer, 'id' => 'in_1', 'status' => 'open']);
    expect($tenant->fresh()->past_due_since)->not->toBeNull();
    StripeBilling::handle('invoice.paid', ['customer' => $customer, 'id' => 'in_1', 'status' => 'paid']);
    expect($tenant->fresh()->past_due_since)->toBeNull();
    StripeBilling::handle('customer.subscription.updated', ['customer' => $customer, 'status' => 'past_due', 'items' => ['data' => [['price' => ['id' => 'price_pro_y']]]]]);
    expect($tenant->fresh())->billing_interval->toBe('yearly')->past_due_since->not->toBeNull();
    StripeBilling::handle('customer.subscription.deleted', ['customer' => $customer]);
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_CANCELLED);

    // The usage of a renewal could not be sent to Stripe: reported, and kept to be sent again.
    $from = now()->subMonth()->startOfDay();
    stripeOverage($tenant, $from);
    StripeBilling::handle('invoice.created', ['id' => 'in_2', 'customer' => $customer, 'billing_reason' => 'subscription_cycle', 'status' => 'draft',
        'period_start' => $from->getTimestamp(), 'period_end' => now()->startOfDay()->getTimestamp()]);
    Exceptions::assertReported(InvalidRequestException::class);
    expect(PlatformInvoice::query()->where('type', 'usage')->first())->stripe_invoice_item_ids->toBeNull()->amount_cents->toBe(2600)
        ->and(collect($stripe->requests)->pluck('path')->unique()->all())->toBe(['/v1/invoiceitems']);
});

it('attaches the usage overage to the renewal invoice once, with idempotent invoice items', function () {
    $items = 0;
    $stripe = stripeFake(['/v1/invoiceitems' => function (array $params) use (&$items) {
        return ['id' => 'ii_'.(++$items), 'object' => 'invoiceitem', 'amount' => $params['amount']];
    }]);
    $tenant = stripeTenant();
    $customer = 'cus_hostrema'.$tenant->team_id;
    $from = now()->subMonth()->startOfDay();
    $to = now()->startOfDay();
    stripeOverage($tenant, $from);
    $renewal = ['id' => 'in_9', 'customer' => $customer, 'billing_reason' => 'subscription_cycle', 'status' => 'draft',
        'period_start' => $from->getTimestamp(), 'period_end' => $to->getTimestamp()];

    StripeBilling::invoiceCreated($renewal);

    $invoice = PlatformInvoice::query()->where('team_id', $tenant->team_id)->where('type', 'usage')->sole();
    expect($invoice)->amount_cents->toBe(2600)->stripe_invoice_id->toBe('in_9')->stripe_invoice_item_ids->toBe(['ii_1', 'ii_2'])
        ->status->toBe(PlatformInvoice::STATUS_OPEN)
        ->and($stripe->requests)->toHaveCount(2)
        ->and(collect($stripe->requests)->pluck('params.amount')->all())->toBe([2500, 100])
        ->and($stripe->requests[0]['params'])->toMatchArray(['customer' => $customer, 'currency' => 'brl', 'invoice' => 'in_9'])
        ->and($stripe->requests[0]['params']['metadata'])->toBe(['platform_invoice' => $invoice->uuid, 'code' => 'egress_overage'])
        ->and($stripe->requests[0]['params']['period'])->toBe(['start' => $from->getTimestamp(), 'end' => $to->getTimestamp()])
        ->and($stripe->header(0, 'Idempotency-Key'))->toBe("platform-{$invoice->uuid}-egress_overage")
        ->and($stripe->header(1, 'Idempotency-Key'))->toBe("platform-{$invoice->uuid}-storage_overage")
        ->and(AuditLog::query()->where('action', 'billing.usage_invoiced')->where('team_id', $tenant->team_id)->exists())->toBeTrue();

    // Stripe delivers the event again: nothing is sent twice.
    StripeBilling::invoiceCreated($renewal);
    expect($stripe->requests)->toHaveCount(2)
        ->and(PlatformInvoice::query()->where('type', 'usage')->count())->toBe(1);
});

it('ignores invoices that are not the draft of a renewal of a known customer with a valid period', function (array $invoice) {
    $stripe = stripeFake();
    $tenant = stripeTenant();
    Subscription::create(['team_id' => 0, 'stripe_customer_id' => 'cus_operator']);
    stripeOverage($tenant, now()->subMonth()->startOfDay());
    $base = ['id' => 'in_1', 'customer' => 'cus_hostrema'.$tenant->team_id, 'billing_reason' => 'subscription_cycle', 'status' => 'draft',
        'period_start' => now()->subMonth()->startOfDay()->getTimestamp(), 'period_end' => now()->startOfDay()->getTimestamp()];

    StripeBilling::invoiceCreated(array_merge($base, $invoice));

    expect(PlatformInvoice::query()->count())->toBe(0)->and($stripe->requests)->toBe([]);
})->with([
    'first invoice of a subscription' => [['billing_reason' => 'subscription_create']],
    'already finalized' => [['status' => 'open']],
    'unknown customer' => [['customer' => 'cus_unknown']],
    'customer of the operator' => [['customer' => 'cus_operator']],
    'period without an end' => [['period_end' => null]],
    'period that is not a timestamp' => [['period_start' => 'ontem']],
    'period ending before it starts' => [['period_end' => 1000]],
]);

it('records an empty usage period as paid without asking Stripe', function () {
    $stripe = stripeFake();
    $tenant = stripeTenant();

    $invoice = StripeBilling::billUsage($tenant, now()->subMonth(), now(), 'in_5');

    expect($invoice)->amount_cents->toBe(0)->status->toBe(PlatformInvoice::STATUS_PAID)->stripe_invoice_id->toBe('in_5')
        ->and($stripe->requests)->toBe([]);
});

it('follows the Stripe subscription state of the tenant', function (string $status, string $expected, ?string $reason, bool $pastDue) {
    $tenant = stripeTenant();

    $synced = StripeBilling::syncSubscription(['customer' => 'cus_hostrema'.$tenant->team_id, 'status' => $status,
        'items' => ['data' => [['price' => ['id' => 'price_pro_m'], 'current_period_start' => now()->subDay()->getTimestamp(), 'current_period_end' => now()->addMonth()->getTimestamp()]]]]);

    expect($synced)->status->toBe($expected)->suspension_reason->toBe($reason)
        ->and(! is_null($synced->past_due_since))->toBe($pastDue)
        ->and($synced->current_period_end->toDateString())->toBe(now()->addMonth()->toDateString());
})->with([
    'active' => ['active', PlatformTenant::STATUS_ACTIVE, null, false],
    'past due' => ['past_due', PlatformTenant::STATUS_ACTIVE, null, true],
    'unpaid' => ['unpaid', PlatformTenant::STATUS_ACTIVE, null, true],
    'paused' => ['paused', PlatformTenant::STATUS_SUSPENDED, TenantLifecycle::REASON_OVERDUE, false],
    'canceled' => ['canceled', PlatformTenant::STATUS_CANCELLED, TenantLifecycle::REASON_CANCELLED, false],
    'expired before the first payment' => ['incomplete_expired', PlatformTenant::STATUS_CANCELLED, TenantLifecycle::REASON_CANCELLED, false],
    'waiting for the first payment' => ['incomplete', PlatformTenant::STATUS_ACTIVE, null, false],
]);

it('keeps the first day a subscription became past due', function () {
    $tenant = stripeTenant(['past_due_since' => now()->subDays(5)]);

    StripeBilling::syncSubscription(['customer' => 'cus_hostrema'.$tenant->team_id, 'status' => 'past_due']);
    StripeBilling::invoicePaymentFailed(['customer' => 'cus_hostrema'.$tenant->team_id, 'id' => 'in_1', 'status' => 'open']);

    expect($tenant->fresh()->past_due_since->toDateString())->toBe(now()->subDays(5)->toDateString());
    Queue::assertNotPushed(PlatformNoticeJob::class);
});

it('starts again a tenant stopped for an unpaid subscription once it is active again', function () {
    $tenant = stripeTenant([
        'status' => PlatformTenant::STATUS_HARD_SUSPENDED, 'suspension_reason' => TenantLifecycle::REASON_OVERDUE,
        'suspended_at' => now()->subDays(40), 'hard_suspended_at' => now()->subDays(10), 'past_due_since' => now()->subDays(45),
        'stopped_resources' => [['type' => 'App\\Models\\StandalonePostgresql', 'id' => 987654]],
    ]);

    $synced = StripeBilling::syncSubscription(['customer' => 'cus_hostrema'.$tenant->team_id, 'status' => 'active']);

    expect($synced)->status->toBe(PlatformTenant::STATUS_ACTIVE)->past_due_since->toBeNull()->stopped_resources->toBeNull()
        ->hard_suspended_at->toBeNull();
    Queue::assertPushed(PlatformNoticeJob::class, fn ($job) => $job->subject === 'Your account is active again');
    expect(Queue::pushed(JobDecorator::class)->filter(fn ($job) => $job->getAction() instanceof StartDatabase))->toHaveCount(0);
});

it('finds the tenant of a Stripe object by its metadata or its customer, never the operator', function () {
    $tenant = stripeTenant();
    Subscription::create(['team_id' => 0, 'stripe_customer_id' => 'cus_operator']);
    $provisioning = fn () => Queue::pushed(JobDecorator::class)->filter(fn ($job) => $job->getAction() instanceof ProvisionTenant)->count();
    $before = $provisioning();

    expect(StripeBilling::tenantFor(['metadata' => ['team_id' => $tenant->team_id]])->id)->toBe($tenant->id)
        ->and(StripeBilling::tenantFor(['customer' => 'cus_hostrema'.$tenant->team_id])->id)->toBe($tenant->id)
        ->and(StripeBilling::tenantFor(['customer' => 'cus_unknown']))->toBeNull()
        ->and(StripeBilling::tenantFor(['metadata' => ['team_id' => 987654]]))->toBeNull()
        ->and(StripeBilling::tenantFor(['metadata' => ['team_id' => 0]]))->toBeNull()
        ->and(StripeBilling::tenantFor(['customer' => 'cus_operator']))->toBeNull()
        ->and(StripeBilling::tenantForCustomer('cus_operator'))->toBeNull()
        ->and(StripeBilling::syncSubscription(['customer' => 'cus_unknown', 'status' => 'active']))->toBeNull();

    StripeBilling::subscriptionDeleted(['customer' => 'cus_unknown']);
    StripeBilling::invoicePaid(['customer' => 'cus_unknown', 'id' => 'in_x', 'status' => 'paid']);
    StripeBilling::invoicePaymentFailed(['customer' => 'cus_operator', 'id' => 'in_x', 'status' => 'open']);
    Queue::assertNotPushed(PlatformNoticeJob::class);
    expect($provisioning())->toBe($before);
});

it('mirrors every final state of a Stripe invoice on the usage invoices attached to it', function () {
    $tenant = stripeTenant();
    $invoice = StripeBilling::billUsage($tenant, now()->subMonths(2), now()->subMonth(), 'in_7');
    $invoice->update(['status' => PlatformInvoice::STATUS_OPEN]);

    StripeBilling::syncInvoiceStatus(['id' => 'in_7', 'status' => 'draft']);
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_OPEN);
    StripeBilling::syncInvoiceStatus(['id' => 'in_7', 'status' => 'uncollectible']);
    expect($invoice->fresh())->status->toBe(PlatformInvoice::STATUS_UNCOLLECTIBLE)->paid_at->toBeNull();
    StripeBilling::syncInvoiceStatus(['id' => 'in_7', 'status' => 'void']);
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_VOID);

    StripeBilling::handle('charge.refunded', ['id' => 'ch_1']);
    StripeBilling::chargeRefunded(['invoice' => 'in_7', 'refunded' => false, 'amount_refunded' => 700]);
    expect($invoice->fresh())->status->toBe(PlatformInvoice::STATUS_VOID)
        ->and($invoice->fresh()->metadata['amount_refunded'])->toBe(700);
});

it('opens the Stripe customer portal for owners and admins of a customer with a Stripe account', function () {
    $stripe = stripeFake(['/v1/billing_portal/sessions' => ['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/bps_1']]);
    $tenant = stripeTenant();
    $owner = $tenant->team->members()->first() ?? User::factory()->create();
    if (! $tenant->team->members()->whereKey($owner->id)->exists()) {
        $tenant->team->members()->attach($owner->id, ['role' => 'owner']);
    }
    $this->actingAs($owner);
    session(['currentTeam' => $tenant->team]);

    Livewire::test(\App\Livewire\Platform\Billing::class)->call('portal')->assertRedirect('https://billing.stripe.com/p/session/bps_1');
    expect(collect($stripe->requests)->firstWhere('path', '/v1/billing_portal/sessions')['params'])->toMatchArray(['customer' => 'cus_hostrema'.$tenant->team_id, 'return_url' => route('subscription.show')]);

    // Without a Stripe customer there is no portal; a Stripe failure is shown, not thrown.
    \App\Models\Subscription::query()->where('team_id', $tenant->team_id)->update(['stripe_customer_id' => null]);
    session(['currentTeam' => $tenant->team->fresh()]);
    \Illuminate\Support\Facades\Cache::forget('team:'.$owner->id);
    Livewire::test(\App\Livewire\Platform\Billing::class)->call('portal')
        ->assertDispatched('error', fn (string $event, array $params) => $params[0] === 'No billing account found. Choose a plan first.');
    \App\Models\Subscription::query()->where('team_id', $tenant->team_id)->update(['stripe_customer_id' => 'cus_sem_portal']);
    session(['currentTeam' => $tenant->team->fresh()]);
    \Illuminate\Support\Facades\Cache::forget('team:'.$owner->id);
    stripeFake();
    Livewire::test(\App\Livewire\Platform\Billing::class)->call('portal')
        ->assertDispatched('error', fn (string $event, array $params) => str_contains((string) $params[0], 'No such route: /v1/billing_portal/sessions'));

    $member = User::factory()->create();
    $tenant->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    Livewire::test(\App\Livewire\Platform\Billing::class)->call('portal')
        ->assertDispatched('error', fn (string $event, array $params) => $params[0] === 'Only team owners and admins can manage the subscription.')
        ->assertNoRedirect();
});
