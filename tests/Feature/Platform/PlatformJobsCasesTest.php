<?php

use App\Actions\Application\StopApplication;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Platform\ProvisionTenant;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\PlatformBillingCycleJob;
use App\Jobs\PlatformCollectServerUsageJob;
use App\Jobs\PlatformCollectUsageJob;
use App\Jobs\PlatformDailyMaintenanceJob;
use App\Jobs\PlatformEnforcementJob;
use App\Jobs\PlatformNoticeJob;
use App\Jobs\PlatformRollupUsageJob;
use App\Jobs\PlatformWebhookDeliveryJob;
use App\Models\AggregatedUsageHourly;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PlatformInvoice;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\PlatformWebhookDelivery;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\TenantLifecycle;
use App\Services\Platform\UsageCollector;
use App\Services\Platform\Webhooks;
use App\Services\Platform\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config([
        'constants.coolify.self_hosted' => false,
        'constants.ssh.mux_enabled' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.billing.provider' => 'efi',
        'platform.metering.interval_minutes' => 5,
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.com.br']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
    WebhookUrlGuard::resolveUsing(fn () => ['93.184.216.34']);
});

afterEach(fn () => WebhookUrlGuard::resolveUsing(null));

function jobsNode(?PlatformRegion $region = null, bool $reachable = true): Server
{
    $region ??= PlatformRegion::query()->firstOrCreate(['slug' => 'sa-east-1'], ['name' => 'São Paulo', 'is_default' => true]);
    $key = PrivateKey::query()->firstOrCreate(['name' => 'jobs-node', 'team_id' => 0], [
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    $node = Server::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => 'node-'.fake()->unique()->numberBetween(1, 9999),
        'ip' => '10.20.9.'.fake()->unique()->numberBetween(2, 250), 'team_id' => 0,
        'private_key_id' => $key->id, 'platform_region_id' => $region->id,
    ]);
    $node->settings->forceFill(['is_reachable' => $reachable, 'is_usable' => $reachable])->save();

    return $node->fresh();
}

function jobsTenant(array $attributes = []): PlatformTenant
{
    $tenant = PlatformTenant::forTeam(Team::factory()->create());
    $tenant->update(array_merge(['plan' => 'pro', 'status' => PlatformTenant::STATUS_ACTIVE, 'provisioned_at' => now()->subMonth()], $attributes));

    return $tenant->fresh();
}

/**
 * A paying team with workloads on a node: running and stopped applications, a service and a
 * running PostgreSQL with one enabled and one disabled scheduled backup.
 *
 * @return array{tenant: PlatformTenant, node: Server, destination: StandaloneDocker, running: Application, exited: Application, service: Service, database: StandalonePostgresql, backup: ScheduledDatabaseBackup}
 */
function jobsWorld(): array
{
    $node = jobsNode();
    auth()->forgetUser();
    $team = User::factory()->create()->teams()->first();
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(['plan' => 'pro', 'status' => PlatformTenant::STATUS_ACTIVE, 'platform_region_id' => $node->platform_region_id, 'provisioned_at' => now()->subMonth()]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => "tenant-{$team->id}-{$node->id}", 'network' => "tenant-{$team->id}",
        'server_id' => $node->id, 'team_id' => $team->id,
    ]));
    $project = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Loja', 'team_id' => $team->id]);
    $environment = $project->environments()->first();
    $application = fn (string $name, string $status) => tap(Application::create([
        'name' => $name, 'git_repository' => 'https://github.com/coollabsio/coolify-examples', 'git_branch' => 'main',
        'build_pack' => 'nixpacks', 'ports_exposes' => '3000', 'environment_id' => $environment->id,
        'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class,
    ]), fn (Application $app) => $app->forceFill(['status' => $status])->save());
    $running = $application('shop', 'running:healthy');
    $exited = $application('old-shop', 'exited');
    $service = Service::create([
        'name' => 'uptime', 'docker_compose_raw' => "services:\n  web:\n    image: 'nginx:alpine'\n",
        'environment_id' => $environment->id, 'server_id' => $node->id,
        'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class,
    ]);
    $database = StandalonePostgresql::create([
        'name' => 'db', 'postgres_user' => 'app', 'postgres_password' => 'secret-password', 'postgres_db' => 'app',
        'environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class,
    ]);
    $database->forceFill(['status' => 'running:healthy'])->save();
    $backup = fn (bool $enabled) => ScheduledDatabaseBackup::forceCreate([
        'uuid' => (string) new Cuid2, 'enabled' => $enabled, 'save_s3' => false, 'frequency' => '0 3 * * *',
        'database_type' => $database->getMorphClass(), 'database_id' => $database->id, 'team_id' => $team->id,
    ]);
    $enabledBackup = $backup(true);
    $backup(false);

    return ['tenant' => $tenant->fresh(), 'node' => $node, 'destination' => $destination, 'running' => $running->fresh(), 'exited' => $exited->fresh(),
        'service' => $service, 'database' => $database->fresh(), 'backup' => $enabledBackup];
}

/**
 * Queued actions of one class (Laravel Actions are queued as a JobDecorator).
 *
 * @return \Illuminate\Support\Collection<int, JobDecorator>
 */
function jobsQueuedActions(string $action): \Illuminate\Support\Collection
{
    return Queue::pushed(JobDecorator::class)->filter(fn (JobDecorator $job) => $job->getAction() instanceof $action)->values();
}

it('queues the usage collection of every reachable node of a region, with the storage at the start of the hour', function () {
    $node = jobsNode();
    jobsNode(reachable: false);
    Server::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'outside', 'ip' => '10.20.9.251', 'team_id' => 0, 'private_key_id' => $node->private_key_id])
        ->settings->forceFill(['is_reachable' => true, 'is_usable' => true])->save();
    $this->travelTo(now()->startOfHour()->addMinutes(2));

    (new PlatformCollectUsageJob)->handle();
    // A node whose collection is still queued is not queued twice.
    (new PlatformCollectUsageJob)->handle();

    Queue::assertPushed(PlatformCollectServerUsageJob::class, 1);
    Queue::assertPushed(PlatformCollectServerUsageJob::class, fn ($job) => $job->serverId === $node->id && $job->withStorage && $job->uniqueId() === (string) $node->id);
});

it('collects the storage only once an hour, and nothing outside the platform', function () {
    jobsNode();
    $this->travelTo(now()->startOfHour()->addMinutes(30));

    (new PlatformCollectUsageJob)->handle();
    Queue::assertPushed(PlatformCollectServerUsageJob::class, fn ($job) => ! $job->withStorage);

    config(['platform.enabled' => false]);
    Cache::flush();
    (new PlatformCollectUsageJob)->handle();
    Queue::assertPushed(PlatformCollectServerUsageJob::class, 1);
});

it('lets the unique lock of a scheduled job expire, so a job lost from the queue does not stop it for good', function (string $job, int $seconds) {
    $node = jobsNode();
    $make = fn () => $job === PlatformCollectServerUsageJob::class ? new $job($node->id) : new $job;
    expect($make()->uniqueFor)->toBe($seconds)
        ->and($seconds)->toBeGreaterThan((int) $make()->timeout);

    dispatch($make());
    dispatch($make());
    Queue::assertPushed($job, 1);

    $this->travel($seconds + 1)->seconds();
    dispatch($make());
    Queue::assertPushed($job, 2);
})->with([
    'collection fan-out' => [PlatformCollectUsageJob::class, 600],
    'collection of a node' => [PlatformCollectServerUsageJob::class, 600],
    'hourly rollup' => [PlatformRollupUsageJob::class, 3600],
    'enforcement' => [PlatformEnforcementJob::class, 1200],
    'daily maintenance' => [PlatformDailyMaintenanceJob::class, 7200],
    'billing cycle' => [PlatformBillingCycleJob::class, 900],
]);

it('collects the usage of a node into events of the tenants, timed by the previous collection', function () {
    $world = jobsWorld();
    Cache::forget('platform:metering:resources');
    $operatorProject = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Operação', 'team_id' => 0]);
    $operatorDestination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => 'operator', 'network' => 'operator', 'server_id' => $world['node']->id, 'team_id' => 0,
    ]));
    $operatorApp = Application::create([
        'name' => 'status-page', 'git_repository' => 'https://github.com/coollabsio/coolify-examples', 'git_branch' => 'main',
        'build_pack' => 'nixpacks', 'ports_exposes' => '3000', 'environment_id' => $operatorProject->environments()->first()->id,
        'destination_id' => $operatorDestination->id, 'destination_type' => StandaloneDocker::class,
    ]);
    $shop = $world['running'];
    $output = fn (string $tx) => implode("\n", [
        UsageCollector::PS_MARKER,
        "c1\t/{$shop->uuid}-120000\t",
        "c2\t{$operatorApp->uuid}-120000\t",
        "c3\tidle-{$shop->uuid}\t{$world['tenant']->team_id}",
        '',
        UsageCollector::STATS_MARKER,
        "c1\t25.00%\t1.5GiB / 4GiB\t1kB / {$tx}",
        "c2\t90.00%\t1GiB / 4GiB\t1kB / 9GB",
        "c3\t0.00%\t0B / 4GiB\t0B / 0B",
    ]);
    Process::fake(['*' => Process::result(output: $output('1GB'))]);
    $this->freezeTime();

    (new PlatformCollectServerUsageJob($world['node']->id))->handle();

    $events = UsageEvent::query()->get();
    expect($events->pluck('team_id')->unique()->all())->toBe([$world['tenant']->team_id])
        ->and($events->where('metric', 'cpu_seconds')->sum('value'))->toBe(75.0)
        ->and($events->where('metric', 'memory_byte_seconds')->sum('value'))->toBe(1.5 * 1024 ** 3 * 300)
        // The first sample of a container only sets the baseline of its traffic.
        ->and($events->where('metric', 'egress_bytes'))->toHaveCount(0)
        ->and($events->every(fn (UsageEvent $event) => $event->metadata['interval'] === 300 && $event->region === 'sa-east-1'))->toBeTrue()
        ->and($events->firstWhere('metric', 'cpu_seconds'))->resource_id->toBe($shop->id)->resource_type->toBe(Application::class);

    // Two minutes later: the interval is the time since the previous collection.
    $this->travel(2)->minutes();
    Process::fake(['*' => Process::result(output: $output('1.5TB'))]);
    (new PlatformCollectServerUsageJob($world['node']->id))->handle();

    $second = UsageEvent::query()->where('recorded_at', now())->get();
    expect($second->firstWhere('metric', 'cpu_seconds')->value)->toBe(30.0)
        ->and($second->firstWhere('metric', 'cpu_seconds')->metadata['interval'])->toBe(120)
        ->and($second->firstWhere('metric', 'egress_bytes')->value)->toBe(1.5e12 - 1e9);
});

it('skips the usage collection of unknown and unreachable nodes', function () {
    Process::fake();
    $down = jobsNode(reachable: false);

    (new PlatformCollectServerUsageJob(987654))->handle();
    (new PlatformCollectServerUsageJob($down->id))->handle();

    Process::assertNothingRan();
    expect(UsageEvent::query()->count())->toBe(0);
});

it('rolls up the last hours and the current one, only on the platform', function () {
    $team = jobsTenant()->team_id;
    $this->travelTo(now()->startOfHour()->addMinutes(20));
    foreach ([now()->subHours(2), now()->subHours(5), now()] as $at) {
        UsageEvent::query()->create(['team_id' => $team, 'metric' => 'cpu_seconds', 'value' => 60, 'recorded_at' => $at]);
    }

    config(['platform.enabled' => false]);
    (new PlatformRollupUsageJob)->handle();
    expect(AggregatedUsageHourly::query()->count())->toBe(0);

    config(['platform.enabled' => true]);
    (new PlatformRollupUsageJob)->handle();
    expect(AggregatedUsageHourly::query()->orderBy('period_start')->pluck('period_start')->map->toDateTimeString()->all())
        ->toBe([now()->subHours(2)->startOfHour()->toDateTimeString(), now()->startOfHour()->toDateTimeString()]);

    (new PlatformRollupUsageJob(6))->handle();
    expect(AggregatedUsageHourly::query()->count())->toBe(3);
});

it('enforces the billing state of every tenant, and a failure of one does not stop the others', function () {
    Exceptions::fake();
    $overdue = jobsTenant(['past_due_since' => now()->subDays(10)]);
    $broken = jobsTenant(['past_due_since' => now()->subDays(10)]);
    $pending = jobsTenant(['status' => PlatformTenant::STATUS_PENDING, 'past_due_since' => now()->subDays(10)]);
    $manual = jobsTenant(['status' => PlatformTenant::STATUS_SUSPENDED, 'suspension_reason' => TenantLifecycle::REASON_MANUAL, 'past_due_since' => now()->subDays(40)]);
    $paid = jobsTenant(['status' => PlatformTenant::STATUS_SUSPENDED, 'suspension_reason' => TenantLifecycle::REASON_OVERDUE, 'suspended_at' => now()->subDays(3)]);
    $limited = jobsTenant(['status' => PlatformTenant::STATUS_LIMITED, 'suspension_reason' => TenantLifecycle::REASON_QUOTA, 'quota_exceeded_at' => now()->subDay()]);
    PlatformTenant::updating(function (PlatformTenant $tenant) use ($broken) {
        if ($tenant->id === $broken->id) {
            throw new RuntimeException('database hiccup');
        }
    });

    config(['platform.enabled' => false]);
    (new PlatformEnforcementJob)->handle();
    expect($overdue->fresh()->status)->toBe(PlatformTenant::STATUS_ACTIVE);

    config(['platform.enabled' => true]);
    (new PlatformEnforcementJob)->handle();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'database hiccup');
    expect($overdue->fresh()->status)->toBe(PlatformTenant::STATUS_SUSPENDED)
        ->and($broken->fresh()->status)->toBe(PlatformTenant::STATUS_ACTIVE)
        ->and($pending->fresh()->status)->toBe(PlatformTenant::STATUS_PENDING)
        // Suspended by the operator: only the operator lifts it, whatever the invoices say.
        ->and($manual->fresh())->status->toBe(PlatformTenant::STATUS_SUSPENDED)->hard_suspended_at->toBeNull()
        // Suspended for an invoice that is no longer overdue: active again.
        ->and($paid->fresh())->status->toBe(PlatformTenant::STATUS_ACTIVE)->suspended_at->toBeNull()
        // Back under the plan quotas: deployments allowed again.
        ->and($limited->fresh())->status->toBe(PlatformTenant::STATUS_ACTIVE)->quota_exceeded_at->toBeNull();
    expect(TenantLifecycle::evaluate($pending->fresh())->status)->toBe(PlatformTenant::STATUS_PENDING)
        ->and(TenantLifecycle::evaluate(jobsTenant(['plan' => null, 'past_due_since' => now()->subDays(40)]))->status)->toBe(PlatformTenant::STATUS_ACTIVE);
});

it('backs up the databases before stopping every running workload of a tenant 30 days overdue', function () {
    $world = jobsWorld();
    $this->freezeTime();
    $world['tenant']->update(['past_due_since' => now()->subDays(31)]);

    TenantLifecycle::evaluate($world['tenant']->fresh());

    $tenant = $world['tenant']->fresh();
    expect($tenant)->status->toBe(PlatformTenant::STATUS_HARD_SUSPENDED)->hard_suspended_at->not->toBeNull()
        ->and($tenant->stopped_resources)->toBe([
            ['type' => $world['running']->getMorphClass(), 'id' => $world['running']->id],
            ['type' => $world['service']->getMorphClass(), 'id' => $world['service']->id],
            ['type' => $world['database']->getMorphClass(), 'id' => $world['database']->id],
        ]);
    Queue::assertPushed(DatabaseBackupJob::class, 1);
    Queue::assertPushed(DatabaseBackupJob::class, fn ($job) => $job->backup->is($world['backup']));
    // The workloads stop after the backups had time to finish.
    foreach ([StopApplication::class => $world['running'], StopService::class => $world['service'], StopDatabase::class => $world['database']] as $action => $resource) {
        $queued = jobsQueuedActions($action);
        expect($queued)->toHaveCount(1)
            ->and($queued->first()->getParameters()[0]->is($resource))->toBeTrue()
            ->and($queued->first()->delay->equalTo(now()->addMinutes(15)))->toBeTrue();
    }
    Queue::assertPushed(PlatformNoticeJob::class, fn ($job) => $job->subject === 'Your workloads were stopped');
});

it('stops at once when no backup is due, and keeps what an earlier suspension stopped', function () {
    $world = jobsWorld();
    $world['backup']->update(['enabled' => false]);
    $this->freezeTime();
    $earlier = ['type' => $world['exited']->getMorphClass(), 'id' => $world['exited']->id];
    $world['tenant']->update(['stopped_resources' => [$earlier]]);

    TenantLifecycle::hardSuspend($world['tenant']->fresh(), TenantLifecycle::REASON_CANCELLED);

    Queue::assertNotPushed(DatabaseBackupJob::class);
    expect(jobsQueuedActions(StopApplication::class)->first()->delay->equalTo(now()))->toBeTrue()
        ->and($world['tenant']->fresh()->stopped_resources[0])->toBe($earlier)
        ->and($world['tenant']->fresh()->stopped_resources)->toHaveCount(4)
        ->and($world['tenant']->fresh()->suspension_reason)->toBe(TenantLifecycle::REASON_CANCELLED);
});

it('starts again what the suspension stopped once the invoice is paid, skipping what is gone or broken', function () {
    Exceptions::fake();
    $world = jobsWorld();
    $broken = Application::create([
        'name' => 'broken', 'git_repository' => 'https://github.com/coollabsio/coolify-examples', 'git_branch' => 'main',
        'build_pack' => 'nixpacks', 'ports_exposes' => '3000', 'environment_id' => $world['running']->environment_id,
        'destination_id' => $world['destination']->id, 'destination_type' => StandaloneDocker::class,
    ]);
    Application::query()->whereKey($broken->id)->update(['destination_id' => 987654]);
    $world['tenant']->update([
        'status' => PlatformTenant::STATUS_HARD_SUSPENDED, 'suspension_reason' => TenantLifecycle::REASON_OVERDUE,
        'suspended_at' => now()->subDays(20), 'hard_suspended_at' => now()->subDays(5), 'past_due_since' => null,
        'stopped_resources' => [
            ['type' => $world['running']->getMorphClass(), 'id' => $world['running']->id],
            ['type' => $world['service']->getMorphClass(), 'id' => $world['service']->id],
            ['type' => $world['database']->getMorphClass(), 'id' => $world['database']->id],
            ['type' => Application::class, 'id' => 987654],
            ['type' => 'App\\Models\\NoLongerExists', 'id' => 1],
            ['type' => Application::class, 'id' => $broken->id],
        ],
    ]);

    TenantLifecycle::evaluate($world['tenant']->fresh());

    $tenant = $world['tenant']->fresh();
    expect($tenant)->status->toBe(PlatformTenant::STATUS_ACTIVE)->stopped_resources->toBeNull()->hard_suspended_at->toBeNull()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $world['running']->id)->exists())->toBeTrue()
        ->and(jobsQueuedActions(StartService::class)->first()->getParameters()[0]->is($world['service']))->toBeTrue()
        ->and(jobsQueuedActions(StartDatabase::class)->first()->getParameters()[0]->is($world['database']))->toBeTrue();
    // The application whose destination is gone is reported; the others still start.
    Exceptions::assertReported(fn (ErrorException $e) => str_contains($e->getMessage(), 'property "server" on null'));
    Queue::assertPushed(PlatformNoticeJob::class, fn ($job) => $job->subject === 'Your account is active again');
});

it('notifies nobody about a tenant without a team', function () {
    TenantLifecycle::notify(new PlatformTenant(['team_id' => 987654]), 'Assunto', ['linha']);

    Queue::assertNotPushed(PlatformNoticeJob::class);
});

it('runs the daily maintenance: reprovisioning, reconciliation of yesterday and pruning', function () {
    config(['platform.metering.raw_retention_days' => 30, 'platform.webhooks.retention_days' => 14]);
    $provisioned = jobsTenant();
    jobsTenant(['provisioned_at' => null]);
    jobsTenant(['plan' => null]);
    $team = $provisioned->team_id;
    UsageEvent::query()->create(['team_id' => $team, 'metric' => 'cpu_seconds', 'value' => 3600, 'recorded_at' => now()->subDay()->startOfDay()->addHours(4)]);
    $old = UsageEvent::query()->create(['team_id' => $team, 'metric' => 'cpu_seconds', 'value' => 1, 'recorded_at' => now()->subDays(31)]);
    $webhook = Webhooks::create($team, 'https://hooks.example.com/ci', ['*']);
    $this->travelTo(now()->subDays(15));
    $stale = Webhooks::sendTest($webhook);
    $this->travelBack();
    $recent = Webhooks::sendTest($webhook);

    // Setting the plan of a provisioned tenant already queued its provisioning.
    $before = jobsQueuedActions(ProvisionTenant::class)->count();

    config(['platform.enabled' => false]);
    (new PlatformDailyMaintenanceJob)->handle();
    expect(jobsQueuedActions(ProvisionTenant::class))->toHaveCount($before);

    config(['platform.enabled' => true]);
    (new PlatformDailyMaintenanceJob)->handle();

    expect(jobsQueuedActions(ProvisionTenant::class)->slice($before)->map->getParameters()->values()->all())->toBe([[$team]])
        // Yesterday had no rollup: aggregated again.
        ->and((float) AggregatedUsageHourly::query()->where('team_id', $team)->sum('value'))->toBe(3600.0)
        ->and(UsageEvent::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(UsageEvent::query()->count())->toBe(1)
        ->and(PlatformWebhookDelivery::query()->pluck('id')->all())->toBe([$recent->id])
        ->and($stale->id)->not->toBe($recent->id);
    // Efí renewals already carry the usage: no separate usage invoice.
    expect(PlatformInvoice::query()->where('type', 'usage')->count())->toBe(0);
});

it('bills the ended Stripe periods once, and a billing failure does not skip the reprovisioning', function () {
    Exceptions::fake();
    config(['platform.billing.provider' => 'stripe']);
    $attributes = ['billing_interval' => 'monthly', 'current_period_start' => now()->subDays(2)];
    $billed = jobsTenant($attributes);
    $broken = jobsTenant($attributes);
    $future = jobsTenant(['current_period_start' => now()->addDay()]);
    $ancient = jobsTenant(['current_period_start' => now()->subDays(41)]);
    $efi = jobsTenant($attributes + ['billing_provider' => PlatformBilling::PROVIDER]);
    PlatformTenant::query()->update(['created_at' => now()->subYear()]);
    $young = jobsTenant($attributes);
    PlatformInvoice::creating(function (PlatformInvoice $invoice) use ($broken) {
        if ($invoice->team_id === $broken->team_id) {
            throw new RuntimeException('Stripe is down');
        }
    });

    $before = jobsQueuedActions(ProvisionTenant::class)->count();

    (new PlatformDailyMaintenanceJob)->handle();
    (new PlatformDailyMaintenanceJob)->handle();

    $usage = PlatformInvoice::query()->where('type', 'usage')->get();
    expect($usage->pluck('team_id')->all())->toBe([$billed->team_id])
        ->and($usage->first()->period_end->toDateString())->toBe(now()->subDays(2)->toDateString())
        ->and($usage->first()->period_start->toDateString())->toBe(now()->subDays(2)->subMonth()->toDateString());
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Stripe is down');
    $reprovisioned = jobsQueuedActions(ProvisionTenant::class)->slice($before)->map(fn ($job) => $job->getParameters()[0]);
    expect($reprovisioned->countBy()->all())
        ->toBe(collect([$billed, $broken, $future, $ancient, $efi, $young])->mapWithKeys(fn ($tenant) => [$tenant->team_id => 2])->all());
});

it('runs the Efí billing cycle only on the platform', function () {
    config(['platform.billing.provider' => 'manual']);
    $tenant = jobsTenant(['billing_provider' => PlatformBilling::PROVIDER, 'billing_interval' => 'monthly',
        'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subHour()]);

    config(['platform.enabled' => false]);
    (new PlatformBillingCycleJob)->handle();
    expect(PlatformInvoice::query()->where('team_id', $tenant->team_id)->count())->toBe(0);

    config(['platform.enabled' => true]);
    (new PlatformBillingCycleJob)->handle();
    expect(PlatformInvoice::query()->where('team_id', $tenant->team_id)->where('type', PlatformInvoice::TYPE_SUBSCRIPTION)->count())->toBe(1);
});

it('gives up on deliveries that are gone, done or whose webhook was disabled', function () {
    Http::fake();
    $webhook = Webhooks::create(jobsTenant()->team_id, 'https://hooks.example.com/ci', ['*']);
    $done = Webhooks::sendTest($webhook);
    $done->update(['status' => PlatformWebhookDelivery::STATUS_SUCCEEDED]);

    (new PlatformWebhookDeliveryJob(987654))->handle();
    (new PlatformWebhookDeliveryJob($done->id))->handle();
    $webhook->update(['enabled' => false]);
    $off = Webhooks::sendTest($webhook);
    (new PlatformWebhookDeliveryJob($off->id))->handle();

    Http::assertNothingSent();
    expect($done->fresh()->status)->toBe(PlatformWebhookDelivery::STATUS_SUCCEEDED)
        ->and($off->fresh())->status->toBe(PlatformWebhookDelivery::STATUS_FAILED)->error->toBe('Webhook disabled.');
});

it('retries deliveries that could not connect, and records the last error once the retries are over', function () {
    $webhook = Webhooks::create(jobsTenant()->team_id, 'https://hooks.example.com/ci', ['*']);
    $delivery = Webhooks::sendTest($webhook);
    $job = new PlatformWebhookDeliveryJob($delivery->id);
    expect($job->backoff())->toHaveCount($job->tries - 1);

    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Webhook delivery failed: cURL error 28');
    expect($delivery->fresh())->status->toBe(PlatformWebhookDelivery::STATUS_PENDING)->attempts->toBe(1)
        ->error->toBe('cURL error 28: Operation timed out');

    $job->failed(new RuntimeException('Max attempts'));
    expect($delivery->fresh())->status->toBe(PlatformWebhookDelivery::STATUS_FAILED)->error->toBe('cURL error 28: Operation timed out')
        ->and($webhook->fresh()->consecutive_failures)->toBe(1);

    // A delivery that succeeded meanwhile stays as it is.
    $later = Webhooks::sendTest($webhook);
    $later->update(['status' => PlatformWebhookDelivery::STATUS_SUCCEEDED]);
    (new PlatformWebhookDeliveryJob($later->id))->failed(null);
    expect($later->fresh()->status)->toBe(PlatformWebhookDelivery::STATUS_SUCCEEDED)
        ->and($webhook->fresh()->consecutive_failures)->toBe(1);
});
