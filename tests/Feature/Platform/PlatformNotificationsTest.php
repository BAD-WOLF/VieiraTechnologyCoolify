<?php

use App\Events\PlatformNotificationPublished;
use App\Livewire\Platform\AdminNotifications;
use App\Livewire\Platform\NotificationBell;
use App\Models\AuditLog;
use App\Models\InstanceSettings;
use App\Models\PlatformNotification;
use App\Models\PlatformNotificationRead;
use App\Models\PlatformTenant;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\PlatformInbox;
use App\Services\Platform\TenantLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Event::fake([PlatformNotificationPublished::class]);
    $this->withoutVite();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.billing.provider' => 'manual',
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.com.br']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

function bellOperator(string $role = 'owner'): User
{
    auth()->forgetUser();
    $user = User::factory()->create(['name' => 'Dona']);
    Team::find(0)->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => Team::find(0)]);

    return $user;
}

/**
 * A customer (owner of its team) logged in, created at the given time.
 *
 * @return array{user: User, team: Team, tenant: PlatformTenant}
 */
function bellCustomer(string $plan = 'pro', ?\Carbon\CarbonInterface $createdAt = null): array
{
    auth()->forgetUser();
    $user = User::factory()->create(['created_at' => $createdAt ?? now()->subMonth()]);
    $team = $user->teams()->first();
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update(['plan' => $plan, 'status' => PlatformTenant::STATUS_ACTIVE]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return ['user' => $user, 'team' => $team, 'tenant' => $tenant->fresh()];
}

function bellTitles(User $user, ?Team $team): array
{
    return PlatformInbox::for($user, $team)->pluck('title')->all();
}

it('shows each announcement only to its audience, while it is live', function () {
    $pro = bellCustomer('pro');
    $basic = bellCustomer('basic');
    $operator = bellOperator();
    $make = fn (string $title, array $attributes = []) => PlatformNotification::factory()->create(array_merge(['title' => $title], $attributes));
    $make('Para todos');
    $make('Para clientes', ['audience' => 'customers']);
    $make('Para a equipe', ['audience' => 'operators']);
    $make('Para o Pro', ['audience' => 'plan', 'plan' => 'pro']);
    $make('Para a loja', ['audience' => 'team', 'team_id' => $basic['team']->id]);
    $make('Agendado', ['published_at' => now()->addHour()]);
    $make('Vencido', ['expires_at' => now()->subMinute()]);

    expect(bellTitles($pro['user'], $pro['team']))->toEqualCanonicalizing(['Para todos', 'Para clientes', 'Para o Pro'])
        ->and(bellTitles($basic['user'], $basic['team']))->toEqualCanonicalizing(['Para todos', 'Para clientes', 'Para a loja'])
        ->and(bellTitles($operator, Team::find(0)))->toEqualCanonicalizing(['Para todos', 'Para a equipe'])
        ->and(bellTitles($pro['user'], null))->toBe(['Para todos']);

    $this->travel(2)->hours();
    expect(bellTitles($pro['user'], $pro['team']))->toContain('Agendado');
});

it('counts as unread only what came after the user joined, and what each one did not read', function () {
    $old = PlatformNotification::factory()->create(['title' => 'Antiga', 'published_at' => now()->subWeeks(2)]);
    $customer = bellCustomer('pro', now()->subWeek());
    $event = PlatformNotification::factory()->forTeam($customer['team']->id)->create(['title' => 'Fatura', 'published_at' => now()->subWeeks(3)]);
    $new = PlatformNotification::factory()->create(['title' => 'Nova']);
    $colleague = User::factory()->create(['created_at' => now()->subMonth()]);
    $customer['team']->members()->attach($colleague->id, ['role' => 'member']);

    expect(PlatformInbox::unreadCount($customer['user'], $customer['team']))->toBe(2)
        ->and(PlatformInbox::for($customer['user'], $customer['team'])->pluck('unread', 'title')->all())->toBe(['Nova' => true, 'Antiga' => false, 'Fatura' => true]);

    PlatformInbox::markRead($customer['user'], $new);
    PlatformInbox::markRead($customer['user'], $new);
    expect(PlatformInbox::unreadCount($customer['user'], $customer['team']))->toBe(1)
        ->and(PlatformInbox::markAllRead($customer['user'], $customer['team']))->toBe(1)
        ->and(PlatformInbox::unreadCount($customer['user'], $customer['team']))->toBe(0)
        // Read per user: the colleague still has both.
        ->and(PlatformInbox::unreadCount($colleague, $customer['team']))->toBe(3)
        ->and(PlatformNotificationRead::query()->where('user_id', $customer['user']->id)->count())->toBe(2)
        ->and($old->fresh())->not->toBeNull()
        ->and($event->reads()->count())->toBe(1);
});

it('shows the bell at the top of the dashboard with the unread count', function () {
    $customer = bellCustomer();
    PlatformNotification::factory()->count(3)->create();

    $this->get(route('subscription.index'))->assertOk()->assertSeeLivewire(NotificationBell::class);
    Livewire::test(NotificationBell::class)
        ->assertSeeHtml('data-unread="3"')
        ->assertSet('loaded', false)
        ->assertDontSee(PlatformNotification::query()->first()->title);

    PlatformNotification::factory()->count(9)->create();
    Livewire::test(NotificationBell::class)->assertSee('9+');
});

it('lists the notifications when the bell opens, opens their links and marks them read', function () {
    $customer = bellCustomer();
    $promo = PlatformNotification::factory()->create(['kind' => 'promotion', 'title' => 'Black Friday', 'body' => "50% no plano anual.\nSó hoje.", 'action_url' => 'https://hostrema.com.br/promo', 'action_label' => 'Ver oferta']);
    $news = PlatformNotification::factory()->create(['title' => 'Nova região']);
    $foreign = PlatformNotification::factory()->forTeam(bellCustomer()['team']->id)->create(['title' => 'De outro cliente']);
    $this->actingAs($customer['user']);
    session(['currentTeam' => $customer['team']]);

    $bell = Livewire::test(NotificationBell::class)
        ->call('load')
        ->assertSee('Black Friday')
        ->assertSee('Promotion')
        ->assertSee('Ver oferta')
        ->assertSee('Só hoje.')
        ->assertDontSee('De outro cliente')
        ->call('markRead', $news->uuid)
        ->assertSeeHtml('data-unread="1"')
        ->call('follow', $promo->uuid)
        ->assertRedirect('https://hostrema.com.br/promo');
    expect(PlatformInbox::unreadCount($customer['user'], $customer['team']))->toBe(0);

    PlatformNotification::factory()->count(2)->create();
    Livewire::test(NotificationBell::class)->call('markAllRead')->assertDontSeeHtml('data-unread');
    Livewire::test(NotificationBell::class)->call('markRead', $foreign->uuid)->assertNotFound();
    PlatformNotification::query()->update(['expires_at' => now()->subMinute()]);
    Livewire::test(NotificationBell::class)->call('load')->assertSee('No notifications yet')->assertDontSee('Black Friday');
});

it('puts the events of an account in the bell of its members, and announces them', function () {
    $customer = bellCustomer();
    $tenant = $customer['tenant'];

    TenantLifecycle::notify($tenant, 'Limite do plano atingido', ['Uso de CPU acima do plano.', '', 'Faça upgrade.']);
    PlatformBilling::notifyIssued(PlatformBilling::createInvoice($tenant, 'subscription', 'pro', 'monthly', now(), now()->addMonth(), [PlatformBilling::planLine('pro', 'monthly', 14990)]));

    $events = PlatformNotification::query()->where('team_id', $tenant->team_id)->orderBy('id')->get();
    expect($events->pluck('kind')->all())->toBe(['account', 'billing'])
        ->and($events[0])->title->toBe('Limite do plano atingido')->body->toBe("Uso de CPU acima do plano.\nFaça upgrade.")
        ->action_url->toBe(route('subscription.show'))->audience->toBe('team')
        ->and($events[1]->title)->toBe('Invoice available')
        ->and(PlatformInbox::unreadCount($customer['user'], $customer['team']))->toBe(2);
    Event::assertDispatched(PlatformNotificationPublished::class, fn ($event) => $event->teamId === $tenant->team_id
        && $event->broadcastOn()[0]->name === "private-team.{$tenant->team_id}");
});

it('keeps the bell out of the operator team and off the platform, and never breaks the event it reports', function () {
    Exceptions::fake();
    $team = bellCustomer()['team'];
    expect(PlatformInbox::toTeam(0, 'account', 'Equipe'))->toBeNull()
        ->and(PlatformInbox::toTeam(987654, 'account', 'Sem equipe'))->toBeNull();

    config(['platform.enabled' => false]);
    expect(PlatformInbox::toTeam($team->id, 'account', 'Fora'))->toBeNull();

    // A failure to record it is reported, and the transaction of the event goes on.
    config(['platform.enabled' => true]);
    PlatformNotification::creating(fn () => throw new RuntimeException('disco cheio'));
    \Illuminate\Support\Facades\DB::transaction(function () use ($team) {
        expect(PlatformInbox::toTeam($team->id, 'account', 'Falhou'))->toBeNull();
        $team->update(['name' => 'Loja atualizada']);
    });
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'disco cheio');
    expect(PlatformNotification::query()->count())->toBe(0)
        ->and($team->fresh()->name)->toBe('Loja atualizada');
    Event::assertNotDispatched(PlatformNotificationPublished::class);
});

it('lets the owner send an announcement to an audience, now or later, and records it', function () {
    InstanceSettings::query()->whereKey(0)->update(['instance_timezone' => 'America/Sao_Paulo']);
    $pro = bellCustomer('pro');
    bellCustomer('basic');
    $owner = bellOperator();

    Livewire::test(AdminNotifications::class)
        ->assertSee('It reaches 3 users.')
        ->set('audience', 'plan')
        ->set('plan', 'pro')
        ->assertSee('It reaches 1 user.')
        ->set('kind', 'promotion')
        ->set('title', 'Desconto no Pro')
        ->set('body', "Use o cupom PRO20.\nVálido até domingo.")
        ->set('actionUrl', 'https://hostrema.com.br/pro')
        ->set('actionLabel', 'Aproveitar')
        ->call('publish')
        ->assertHasNoErrors()
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Announcement sent.' && $params[1] === 'It reaches 1 user.')
        ->assertSet('title', '')
        ->assertSee('Desconto no Pro')
        ->assertSee('Customers of the plan Pro');

    $sent = PlatformNotification::query()->sole();
    expect($sent)->kind->toBe('promotion')->audience->toBe('plan')->plan->toBe('pro')->created_by->toBe($owner->id)
        ->action_label->toBe('Aproveitar')
        ->and(bellTitles($pro['user'], $pro['team']))->toBe(['Desconto no Pro'])
        ->and(AuditLog::query()->where('action', 'platform.notification.published')->exists())->toBeTrue();
    Event::assertDispatched(PlatformNotificationPublished::class, fn ($event) => is_null($event->teamId) && $event->broadcastOn()[0]->name === 'platform-notifications');

    // Scheduled in the time zone of the instance; announced only when it is published.
    Event::fake([PlatformNotificationPublished::class]);
    $at = now()->timezone('America/Sao_Paulo')->addDay()->setTime(9, 0);
    Livewire::test(AdminNotifications::class)
        ->set('title', 'Manutenção programada')
        ->set('kind', 'maintenance')
        ->set('publishAt', $at->format('Y-m-d\TH:i'))
        ->set('expiresAt', $at->copy()->addHours(3)->format('Y-m-d\TH:i'))
        ->call('publish')
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Announcement scheduled for '.$at->format('d/m/Y H:i').'.')
        ->assertSee('Scheduled for '.$at->format('d/m/Y H:i'));
    $scheduled = PlatformNotification::query()->where('kind', 'maintenance')->sole();
    expect($scheduled->published_at->toIso8601String())->toBe($at->copy()->utc()->toIso8601String())
        ->and($scheduled->expires_at->diffInHours($scheduled->published_at, true))->toBe(3.0)
        ->and(bellTitles($pro['user'], $pro['team']))->not->toContain('Manutenção programada');
    Event::assertNotDispatched(PlatformNotificationPublished::class);
});

it('refuses announcements without a title, with a link that is not a web address or to nobody', function (array $values, string $field) {
    bellOperator();

    Livewire::test(AdminNotifications::class)
        ->set(array_merge(['title' => 'Aviso'], $values))
        ->call('publish')
        ->assertHasErrors($field);
    expect(PlatformNotification::query()->count())->toBe(0);
})->with([
    'no title' => [['title' => ''], 'title'],
    'title too long' => [['title' => str_repeat('a', 151)], 'title'],
    'script link' => [['actionUrl' => 'javascript:alert(1)'], 'actionUrl'],
    'unknown type' => [['kind' => 'account'], 'kind'],
    'plan audience without plan' => [['audience' => 'plan'], 'plan'],
    'unknown plan' => [['audience' => 'plan', 'plan' => 'gold'], 'plan'],
    'customer audience without a customer' => [['audience' => 'team'], 'teamId'],
    'customer that does not exist' => [['audience' => 'team', 'teamId' => '987654'], 'teamId'],
    'end before the publication' => [['publishAt' => '2031-01-10T10:00', 'expiresAt' => '2031-01-10T09:00'], 'expiresAt'],
]);

it('lets the owner end and delete announcements, and only the owner change them', function () {
    $customer = bellCustomer();
    $announcement = PlatformNotification::factory()->create(['title' => 'Promoção relâmpago', 'kind' => 'promotion']);
    $event = PlatformNotification::factory()->forTeam($customer['team']->id)->create(['title' => 'Fatura disponível']);

    bellOperator('admin');
    Livewire::test(AdminNotifications::class)
        ->assertSee('Promoção relâmpago')
        ->assertDontSee('Fatura disponível')
        ->assertSee('Only the owner of the root team can send announcements.')
        ->set('title', 'Tentativa')
        ->call('publish')
        ->assertDispatched('error')
        ->call('end', $announcement->uuid)
        ->assertDispatched('error');
    expect($announcement->fresh()->expires_at)->toBeNull();

    bellOperator();
    Livewire::test(AdminNotifications::class)
        ->call('end', $announcement->uuid)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Announcement ended: it left the bell of the users.');
    expect($announcement->fresh()->expires_at)->not->toBeNull()
        ->and(bellTitles($customer['user'], $customer['team']))->toBe(['Fatura disponível']);

    Livewire::test(AdminNotifications::class)->call('delete', $announcement->uuid)->assertDispatched('success');
    expect(PlatformNotification::query()->whereKey($announcement->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'platform.notification.deleted')->exists())->toBeTrue();
    // Events of an account are not announcements: the console does not touch them.
    Livewire::test(AdminNotifications::class)->call('delete', $event->uuid)->assertNotFound();
    expect($event->fresh())->not->toBeNull();

    bellCustomer();
    Livewire::test(AdminNotifications::class)->assertForbidden();
});

it('counts the users each audience reaches', function () {
    $pro = bellCustomer('pro');
    bellCustomer('basic');
    $pro['team']->members()->attach(User::factory()->create()->id, ['role' => 'member']);
    bellOperator();

    // Operators also have a personal team, so they count among the users of customer accounts.
    expect(PlatformInbox::reach('all'))->toBe(4)
        ->and(PlatformInbox::reach('customers'))->toBe(4)
        ->and(PlatformInbox::reach('operators'))->toBe(1)
        ->and(PlatformInbox::reach('plan', 'pro'))->toBe(2)
        ->and(PlatformInbox::reach('plan', 'enterprise'))->toBe(0)
        ->and(PlatformInbox::reach('team', teamId: $pro['team']->id))->toBe(2)
        ->and(PlatformInbox::reach('team', teamId: 0))->toBe(0)
        ->and(PlatformInbox::reach('outro'))->toBe(0);
});
