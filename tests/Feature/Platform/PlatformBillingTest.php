<?php

use App\Actions\Platform\ProvisionTenant;
use App\Jobs\PlatformNoticeJob;
use App\Models\AggregatedUsageHourly;
use App\Models\InstanceSettings;
use App\Models\PlatformInvoice;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PricingEngine;
use App\Services\Platform\StripeBilling;
use App\Services\Platform\TenantLifecycle;
use App\Services\Platform\UsageAggregator;
use App\Services\Platform\UsageCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.currency' => 'brl',
        'platform.overage.egress_gb_cents' => 50,
        'platform.overage.storage_gb_month_cents' => 100,
        'platform.plans.basic.stripe_price_monthly' => 'price_basic_m',
        'platform.plans.pro.stripe_price_monthly' => 'price_pro_m',
        'platform.plans.pro.stripe_price_yearly' => 'price_pro_y',
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0]);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
    Queue::fake();
});

function billingTenant(string $plan = 'pro', string $status = PlatformTenant::STATUS_ACTIVE, string $customer = 'cus_test'): PlatformTenant
{
    $team = Team::factory()->create();
    Subscription::create(['team_id' => $team->id, 'stripe_customer_id' => $customer.$team->id, 'stripe_invoice_paid' => true]);
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(['plan' => $plan, 'status' => $status]);

    return $tenant->fresh();
}

it('maps Stripe prices to plans', function () {
    expect(PlanCatalog::planForPriceId('price_pro_y'))->toBe(['pro', 'yearly'])
        ->and(PlanCatalog::planForPriceId('price_unknown'))->toBeNull()
        ->and(PlanCatalog::isSelfServe('pro'))->toBeTrue()
        ->and(PlanCatalog::isSelfServe('enterprise'))->toBeFalse();
});

it('activates and provisions a tenant when the subscription becomes active', function () {
    $tenant = billingTenant('basic', PlatformTenant::STATUS_PENDING);

    StripeBilling::syncSubscription([
        'customer' => 'cus_test'.$tenant->team_id,
        'status' => 'active',
        'metadata' => ['team_id' => (string) $tenant->team_id],
        'items' => ['data' => [['price' => ['id' => 'price_pro_y']]]],
        'current_period_start' => now()->subDay()->getTimestamp(),
        'current_period_end' => now()->addYear()->getTimestamp(),
    ]);

    $tenant->refresh();
    expect($tenant->plan)->toBe('pro')
        ->and($tenant->billing_interval)->toBe('yearly')
        ->and($tenant->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->current_period_end->isFuture())->toBeTrue();
    Queue::assertPushed(\Lorisleiva\Actions\Decorators\JobDecorator::class);
});

it('tracks failed payments and escalates suspensions over time', function () {
    $tenant = billingTenant();

    StripeBilling::invoicePaymentFailed(['customer' => 'cus_test'.$tenant->team_id, 'id' => 'in_1', 'status' => 'open']);
    expect($tenant->fresh()->past_due_since)->not->toBeNull();
    Queue::assertPushed(PlatformNoticeJob::class);

    $tenant->update(['past_due_since' => now()->subDays(8)]);
    TenantLifecycle::evaluate($tenant->fresh());
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_SUSPENDED)
        ->and($tenant->fresh()->isBlocked())->toBeTrue();

    $tenant->update(['past_due_since' => now()->subDays(31)]);
    TenantLifecycle::evaluate($tenant->fresh());
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_HARD_SUSPENDED)
        ->and($tenant->fresh()->hard_suspended_at)->not->toBeNull();

    StripeBilling::invoicePaid(['customer' => 'cus_test'.$tenant->team_id, 'id' => 'in_1', 'status' => 'paid']);
    $tenant->refresh();
    expect($tenant->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->past_due_since)->toBeNull()
        ->and($tenant->hard_suspended_at)->toBeNull();
});

it('cancels and later stops tenants whose subscription was deleted', function () {
    $tenant = billingTenant();

    StripeBilling::subscriptionDeleted(['customer' => 'cus_test'.$tenant->team_id, 'metadata' => ['team_id' => $tenant->team_id]]);
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_CANCELLED);

    $tenant->update(['suspended_at' => now()->subDays(8)]);
    TenantLifecycle::evaluate($tenant->fresh());
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_HARD_SUSPENDED);
});

it('warns at 80% and soft-throttles tenants above their storage quota', function () {
    $tenant = billingTenant('basic');
    config(['platform.plans.basic.quotas.storage_gb' => 10]);

    UsageEvent::insert([[
        'team_id' => $tenant->team_id, 'metric' => UsageEvent::METRIC_STORAGE_BYTES, 'value' => 8.5e9,
        'recorded_at' => now()->subMinute(), 'created_at' => now(),
    ]]);
    TenantLifecycle::evaluate($tenant);
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($tenant->fresh()->quota_warning_notified_at)->not->toBeNull();
    Queue::assertPushed(PlatformNoticeJob::class, fn ($job) => str_contains($job->subject, 'close to'));

    UsageEvent::insert([[
        'team_id' => $tenant->team_id, 'metric' => UsageEvent::METRIC_STORAGE_BYTES, 'value' => 11e9,
        'recorded_at' => now(), 'created_at' => now(),
    ]]);
    TenantLifecycle::evaluate($tenant->fresh());
    expect($tenant->fresh()->status)->toBe(PlatformTenant::STATUS_LIMITED)
        ->and($tenant->fresh()->blockReason())->toContain('100%');
});

it('counts what the containers use when they share the plan, and what they reserve otherwise', function () {
    $tenant = billingTenant('basic');
    config(['platform.plans.basic.quotas.cpus' => 0.5, 'platform.plans.basic.quotas.memory_mb' => 1536, 'platform.metering.interval_minutes' => 5]);
    $server = Server::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'node', 'ip' => '10.1.1.2', 'team_id' => 0, 'private_key_id' => 1]);
    // A stack of four containers declared with 0.5 vCPU and 512 MB each, on one node.
    \App\Models\PlatformAllocation::create(['team_id' => $tenant->team_id, 'resource_type' => 'App\\Models\\Application', 'resource_id' => 1, 'server_id' => $server->id, 'pull_request_id' => 0, 'cpus' => 2.0, 'memory_mb' => 2048, 'containers' => 4]);
    UsageEvent::insert([
        ['team_id' => $tenant->team_id, 'metric' => UsageEvent::METRIC_CPU_SECONDS, 'value' => 60, 'recorded_at' => now()->subMinute(), 'created_at' => now()],
        ['team_id' => $tenant->team_id, 'metric' => UsageEvent::METRIC_MEMORY_BYTE_SECONDS, 'value' => 300 * 314_572_800, 'recorded_at' => now()->subMinute(), 'created_at' => now()],
    ]);

    $snapshot = \App\Services\Platform\TenantUsage::snapshot($tenant);
    expect($snapshot['cpus'])->toBe(['used' => 0.2, 'limit' => 0.5, 'percent' => 40.0])
        ->and($snapshot['memory_mb']['used'])->toBe(300)
        ->and(TenantLifecycle::evaluate($tenant)->status)->toBe(PlatformTenant::STATUS_ACTIVE);

    config(['platform.container.shared_plan' => false]);
    expect(\App\Services\Platform\TenantUsage::snapshot($tenant->fresh())['cpus']['used'])->toBe(2.0)
        ->and(TenantLifecycle::evaluate($tenant->fresh())->status)->toBe(PlatformTenant::STATUS_LIMITED);
});

it('parses docker output into usage events per tenant', function () {
    $tenant = billingTenant();
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo']);
    $server = Server::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'node', 'ip' => '10.1.1.1', 'team_id' => 0, 'private_key_id' => 1, 'platform_region_id' => $region->id]);
    Cache::put("platform:metering:{$server->id}:tx:abc", 1_000_000, now()->addHour());
    Cache::put('platform:metering:resources', ['res123uuid' => ['team_id' => $tenant->team_id, 'type' => 'App\\Models\\Application', 'id' => 5]], 60);

    $output = implode("\n", [
        UsageCollector::PS_MARKER,
        "abc\tweb-res123uuid\t{$tenant->team_id}",
        "def\tcoolify-proxy\t",
        UsageCollector::STATS_MARKER,
        "abc\t50.00%\t256MiB / 512MiB\t1.5MB / 3MB",
        "def\t5.00%\t20MiB / 1GiB\t1GB / 2GB",
        UsageCollector::STORAGE_MARKER,
        "volume\tres123uuid_data\t2000000000",
        "volume\tsomeone-else\t5000",
        "dir\tres123uuid\t1000000",
        "backup\tmaria-s-team-{$tenant->team_id}\t3000000",
        "backup\troot-team-0\t999",
    ]);
    $sample = UsageCollector::parse($output);

    expect($sample['stats']['abc']['memory_bytes'])->toBe(268435456)
        ->and($sample['stats']['abc']['net_tx_bytes'])->toBe(3_000_000)
        ->and($sample['containers']['abc']['tenant'])->toBe($tenant->team_id)
        ->and($sample['containers']['def']['tenant'])->toBeNull();

    $all = collect(UsageCollector::events($sample, $server, 300, now()->toDateTimeString()));
    $backups = $all->where('metric', 'storage_bytes')->whereNull('resource_id');
    $events = $all->reject(fn (array $event) => $event['metric'] === 'storage_bytes' && is_null($event['resource_id']))->keyBy('metric');

    // Local database backups count towards the storage of their team.
    expect($backups)->toHaveCount(1)
        ->and($backups->first()['team_id'])->toBe($tenant->team_id)
        ->and($backups->first()['value'])->toBe(3_000_000.0);
    expect($events)->toHaveCount(4)
        ->and($events['cpu_seconds']['value'])->toBe(150.0)
        ->and($events['memory_byte_seconds']['value'])->toBe(268435456.0 * 300)
        ->and($events['egress_bytes']['value'])->toBe(2_000_000.0)
        ->and($events['storage_bytes']['value'])->toBe(2_001_000_000.0)
        ->and($events['cpu_seconds']['team_id'])->toBe($tenant->team_id)
        ->and($events['cpu_seconds']['region'])->toBe('sa-east-1');
});

it('converts docker sizes to bytes', function () {
    expect(UsageCollector::toBytes('1.5GiB'))->toBe(1610612736)
        ->and(UsageCollector::toBytes('12.3MB'))->toBe(12_300_000)
        ->and(UsageCollector::toBytes('0B'))->toBe(0)
        ->and(UsageCollector::toBytes('512kB'))->toBe(512_000)
        ->and(UsageCollector::toBytes('--'))->toBe(0);
    expect(implode("\n", UsageCollector::commands(true)))
        ->toContain("printf 'dir\\t%s\\t%s\\n'")
        ->toContain('{{.Label "coolify.tenantId"}}');
});

it('rolls up hourly usage (counters summed, storage averaged per run)', function () {
    $tenant = billingTenant();
    $hour = now()->subHour()->startOfHour();
    $rows = [];
    foreach ([5, 35] as $minute) {
        $at = $hour->copy()->addMinutes($minute)->toDateTimeString();
        $rows[] = ['team_id' => $tenant->team_id, 'metric' => 'cpu_seconds', 'value' => 100, 'recorded_at' => $at, 'created_at' => $at];
        $rows[] = ['team_id' => $tenant->team_id, 'metric' => 'storage_bytes', 'value' => 1e9, 'recorded_at' => $at, 'created_at' => $at];
        $rows[] = ['team_id' => $tenant->team_id, 'metric' => 'storage_bytes', 'value' => 1e9, 'recorded_at' => $at, 'created_at' => $at];
    }
    UsageEvent::insert($rows);

    UsageAggregator::aggregateHour($hour);
    UsageAggregator::aggregateHour($hour);

    $rollups = AggregatedUsageHourly::query()->where('team_id', $tenant->team_id)->get()->keyBy('metric');
    expect($rollups)->toHaveCount(2)
        ->and($rollups['cpu_seconds']->value)->toBe(200.0)
        ->and($rollups['storage_bytes']->value)->toBe(2e9)
        ->and($rollups['storage_bytes']->samples)->toBe(2);
});

it('prices egress and storage overage from rollups', function () {
    $tenant = billingTenant('basic');
    config(['platform.plans.basic.quotas.egress_gb' => 50, 'platform.plans.basic.quotas.storage_gb' => 10]);
    $from = now()->subMonth()->startOfDay();
    $to = now()->startOfDay();
    AggregatedUsageHourly::insert([
        ['team_id' => $tenant->team_id, 'metric' => 'egress_bytes', 'period_start' => $from->copy()->addDay(), 'period_end' => $from->copy()->addDay()->addHour(), 'value' => 60.2e9, 'samples' => 1],
        ['team_id' => $tenant->team_id, 'metric' => 'cpu_seconds', 'period_start' => $from->copy()->addDay(), 'period_end' => $from->copy()->addDay()->addHour(), 'value' => 7200, 'samples' => 1],
    ]);
    $storageRows = [];
    for ($h = 0; $h < 730; $h++) {
        $start = $from->copy()->addHours($h);
        $storageRows[] = ['team_id' => $tenant->team_id, 'metric' => 'storage_bytes', 'period_start' => $start, 'period_end' => $start->copy()->addHour(), 'value' => 12e9, 'samples' => 1];
    }
    foreach (array_chunk($storageRows, 200) as $chunk) {
        AggregatedUsageHourly::insert($chunk);
    }

    $result = PricingEngine::calculate($tenant, $from, $to);
    $lines = collect($result['lines'])->keyBy('code');

    expect($lines['cpu_hours']['quantity'])->toBe(2.0)
        ->and($lines['cpu_hours']['amount_cents'])->toBe(0)
        ->and($lines['egress_overage']['quantity'])->toBe(11.0)
        ->and($lines['egress_overage']['amount_cents'])->toBe(550)
        ->and($lines['storage_overage']['quantity'])->toBe(2.0)
        ->and($lines['storage_overage']['amount_cents'])->toBe(200)
        ->and($result['amount_cents'])->toBe(750)
        ->and($result['currency'])->toBe('brl');
});

it('records usage invoices once per period and mirrors Stripe invoice status', function () {
    $tenant = billingTenant('basic');
    $tenant->team->subscription->update(['stripe_customer_id' => null]);
    $from = now()->subMonth()->startOfDay();
    $to = now()->startOfDay();
    AggregatedUsageHourly::insert([
        ['team_id' => $tenant->team_id, 'metric' => 'egress_bytes', 'period_start' => $from->copy()->addHour(), 'period_end' => $from->copy()->addHours(2), 'value' => 100e9, 'samples' => 1],
    ]);

    $invoice = StripeBilling::billUsage($tenant->fresh(), $from, $to, 'in_123');
    $again = StripeBilling::billUsage($tenant->fresh(), $from, $to, 'in_123');

    expect($invoice->id)->toBe($again->id)
        ->and(PlatformInvoice::query()->count())->toBe(1)
        ->and($invoice->amount_cents)->toBe(2500)
        ->and($invoice->status)->toBe(PlatformInvoice::STATUS_OPEN)
        ->and($invoice->stripe_invoice_id)->toBe('in_123');

    StripeBilling::syncInvoiceStatus(['id' => 'in_123', 'status' => 'paid', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/x']);
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_PAID)
        ->and($invoice->fresh()->stripe_hosted_invoice_url)->toBe('https://invoice.stripe.com/i/x');

    StripeBilling::chargeRefunded(['invoice' => 'in_123', 'refunded' => true, 'amount_refunded' => 2500]);
    expect($invoice->fresh()->status)->toBe(PlatformInvoice::STATUS_REFUNDED);
});

it('provisions tenant destinations on the shared nodes of the region', function () {
    $tenant = billingTenant();
    $region = PlatformRegion::create(['slug' => 'sa-east-1', 'name' => 'São Paulo', 'is_default' => true]);
    $nodes = collect([1, 2])->map(fn ($i) => Server::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => "node-{$i}", 'ip' => "10.9.0.{$i}", 'team_id' => 0, 'private_key_id' => 1, 'platform_region_id' => $region->id,
    ]));
    $dedicated = Server::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'dedicated', 'ip' => '10.9.0.9', 'team_id' => 0, 'private_key_id' => 1, 'platform_region_id' => $region->id, 'platform_dedicated_team_id' => 999999]);

    ProvisionTenant::run($tenant->team_id, null, false);
    ProvisionTenant::run($tenant->team_id, null, false);

    $destinations = StandaloneDocker::query()->where('team_id', $tenant->team_id)->get();
    expect($destinations)->toHaveCount(2)
        ->and($destinations->pluck('server_id')->sort()->values()->all())->toBe($nodes->pluck('id')->sort()->values()->all())
        ->and($destinations->pluck('network')->unique()->all())->toBe(["tenant-{$tenant->team_id}"])
        ->and($tenant->fresh()->platform_region_id)->toBe($region->id)
        ->and($tenant->fresh()->provisioned_at)->not->toBeNull()
        ->and(StandaloneDocker::query()->where('server_id', $dedicated->id)->where('team_id', $tenant->team_id)->exists())->toBeFalse();
});

it('serves Prometheus metrics only with the platform token', function () {
    config(['platform.metrics_token' => 'secret-token']);
    billingTenant();

    $this->get('/api/v1/platform/metrics')->assertNotFound();
    $this->get('/api/v1/platform/metrics', ['Authorization' => 'Bearer wrong'])->assertNotFound();
    $response = $this->get('/api/v1/platform/metrics', ['Authorization' => 'Bearer secret-token']);

    $response->assertOk();
    expect($response->getContent())->toContain('platform_tenants{status="active",plan="pro"} 1')
        ->toContain('# TYPE platform_deployments_pending gauge');
});
