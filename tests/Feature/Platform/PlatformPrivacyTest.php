<?php

use App\Events\PlatformNotificationPublished;
use App\Jobs\DeleteResourceJob;
use App\Jobs\PlatformAccountDeletionJob;
use App\Jobs\PlatformDailyMaintenanceJob;
use App\Jobs\PlatformNoticeJob;
use App\Jobs\PlatformPrivacyNoticeJob;
use App\Livewire\Platform\Admin;
use App\Livewire\Platform\AdminPrivacy;
use App\Livewire\Platform\Privacy as PrivacyPage;
use App\Livewire\Platform\PrivacyNotice;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\InstanceSettings;
use App\Models\PlatformInvoice;
use App\Models\PlatformNotification;
use App\Models\PlatformRegion;
use App\Models\PlatformSetting;
use App\Models\PlatformTenant;
use App\Models\PrivacyConsent;
use App\Models\PrivacyDeletedAccount;
use App\Models\PrivacyIncident;
use App\Models\PrivacyPreference;
use App\Models\PrivacyRequest;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\AccountDeletion;
use App\Services\Platform\PlatformInbox;
use App\Services\Platform\Privacy;
use App\Services\Platform\PrivacyExport;
use App\Services\Platform\PrivacyIncidents;
use App\Services\Platform\PrivacyRetention;
use App\Services\Platform\ProcessingRecord;
use App\Services\Platform\StripeBilling;
use Carbon\Carbon;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Bus::fake([DeleteResourceJob::class]);
    Event::fake([PlatformNotificationPublished::class]);
    Mail::fake();
    Notification::fake();
    $this->withoutVite();
    PlatformSetting::flushMemo();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.billing.provider' => 'manual',
        'platform.support_email' => 'suporte@hostrema.com.br',
        'platform.privacy.controller_name' => 'Hostrema Tecnologia Ltda.',
        'platform.privacy.controller_document' => '12.345.678/0001-90',
        'platform.privacy.controller_address' => 'Av. Paulista, 1000, São Paulo, SP',
        'platform.privacy.contact_email' => 'privacidade@hostrema.com.br',
        'platform.privacy.dpo_name' => 'Maria Encarregada',
        'platform.privacy.dpo_email' => 'dpo@hostrema.com.br',
        'platform.privacy.audit_months' => 12,
        'platform.privacy.fiscal_years' => 5,
        'platform.privacy.unverified_days' => 30,
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://painel.hostrema.com.br']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

/**
 * Stripe API stand-in: answers "METHOD /path" with [status, object] (or a closure of the params)
 * and records the requests; anything else is a missing resource.
 *
 * @param  array<string, array{0: int, 1: array<string, mixed>}|Closure>  $answers
 */
function privacyStripe(array $answers = []): object
{
    $fake = new class($answers) implements ClientInterface
    {
        /** @var list<array{key: string, params: array<string, mixed>}> */
        public array $requests = [];

        public function __construct(private array $answers) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
        {
            $key = strtoupper((string) $method).' '.parse_url($absUrl, PHP_URL_PATH);
            $this->requests[] = ['key' => $key, 'params' => $params];
            foreach ($this->answers as $pattern => $answer) {
                if (Str::is($pattern, $key)) {
                    [$status, $body] = $answer instanceof Closure ? $answer($params) : $answer;

                    return [json_encode($body), $status, []];
                }
            }

            return [json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such resource: {$key}"]]), 404, []];
        }
    };
    ApiRequestor::setHttpClient($fake);

    return $fake;
}

function privacyOwner(string $role = 'owner'): User
{
    auth()->forgetUser();
    $user = User::factory()->create(['name' => 'Dona', 'password' => Hash::make('senha-da-dona')]);
    // Operators can only use the panel with two factors.
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    Team::find(0)->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => Team::find(0)]);

    return $user;
}

/**
 * A customer (alone in its team) logged in, with payer data.
 *
 * @return array{user: User, team: Team, tenant: PlatformTenant}
 */
function privacyCustomer(string $email = 'cliente@loja.com.br'): array
{
    auth()->forgetUser();
    $user = User::factory()->create(['name' => 'Cliente', 'email' => $email, 'password' => Hash::make('senha-do-cliente')]);
    $team = $user->teams()->first();
    $tenant = PlatformTenant::forTeam($team);
    $tenant->update([
        'plan' => 'pro', 'status' => PlatformTenant::STATUS_ACTIVE,
        'billing_profile' => ['name' => 'Loja do Cliente', 'document' => '12345678909', 'email' => 'financeiro@loja.com.br', 'phone' => '11999998888'],
    ]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return ['user' => $user, 'team' => $team, 'tenant' => $tenant->fresh()];
}

/**
 * Sign-up open, with the e-mail that sends the confirmation link configured, as on the platform.
 */
function privacyOpenSignUp(): void
{
    // Through the model: the e-mail settings are encrypted.
    InstanceSettings::query()->findOrFail(0)->forceFill([
        'is_registration_enabled' => true, 'smtp_enabled' => true, 'smtp_host' => 'smtp.hostrema.com.br', 'smtp_port' => 587,
        'smtp_from_address' => 'nao-responda@hostrema.com.br', 'smtp_from_name' => 'Hostrema',
    ])->save();
}

/**
 * The first account of the installation (id 0), member of the root team.
 */
function privacyRootUser(): User
{
    $root = User::withoutEvents(fn () => User::forceCreate(['id' => 0, 'name' => 'Root', 'email' => 'root@hostrema.com.br', 'password' => Hash::make('senha-da-raiz')]));
    Team::find(0)->members()->attach(0, ['role' => 'owner']);

    return $root;
}

function privacyAge(AuditLog $log, \Carbon\CarbonInterface $createdAt): void
{
    // The audit log can not be changed through the model.
    AuditLog::query()->whereKey($log->id)->update(['created_at' => $createdAt]);
}

function privacyInvoice(Team $team, string $status = PlatformInvoice::STATUS_PAID): PlatformInvoice
{
    return PlatformInvoice::create([
        'team_id' => $team->id,
        'type' => 'subscription',
        'period_start' => now()->subMonth()->startOfDay()->addSeconds(PlatformInvoice::query()->count()),
        'period_end' => now(),
        'amount_cents' => 4990,
        'currency' => 'brl',
        'status' => $status,
        'lines' => [],
    ]);
}

function privacyApplication(Team $team): Application
{
    $region = PlatformRegion::query()->firstOrCreate(['slug' => 'sa-east-1'], ['name' => 'São Paulo', 'is_default' => true]);
    $node = Server::query()->where('team_id', 0)->first() ?? Server::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => 'node-1', 'ip' => '10.20.8.10', 'team_id' => 0, 'private_key_id' => 1, 'platform_region_id' => $region->id,
    ]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::forceCreate([
        'uuid' => (string) new Cuid2, 'name' => "tenant-{$team->id}", 'network' => "tenant-{$team->id}", 'server_id' => $node->id, 'team_id' => $team->id,
    ]));
    $project = Project::forceCreate(['uuid' => (string) new Cuid2, 'name' => 'Loja', 'team_id' => $team->id]);

    return Application::create([
        'name' => 'shop', 'git_repository' => 'https://github.com/coollabsio/coolify-examples', 'git_branch' => 'main',
        'build_pack' => 'nixpacks', 'ports_exposes' => '3000', 'environment_id' => $project->environments()->first()->id,
        'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class,
    ]);
}

function runDeletion(PrivacyDeletedAccount $record): PrivacyDeletedAccount
{
    (new PlatformAccountDeletionJob($record->id))->handle();

    return $record->fresh();
}

// ---------------------------------------------------------------------------------------------
// Sign-up (arts. 8º, 9º and 14)
// ---------------------------------------------------------------------------------------------

it('asks at sign-up to accept the policy and the terms and to declare the age, and records the proof', function () {
    privacyOwner();
    auth()->forgetUser();
    privacyOpenSignUp();

    $this->get(route('register'))->assertOk()
        ->assertSee('name="accept_policy"', false)->assertSee('name="adult"', false)->assertSee('name="marketing"', false)
        ->assertSee(route('platform.legal.privacy'), false)->assertSee(route('platform.legal.terms'), false);

    $this->post('/register', ['name' => 'Novo', 'email' => 'novo@cliente.com.br', 'password' => 'Senha-forte-123!', 'password_confirmation' => 'Senha-forte-123!'])
        ->assertSessionHasErrors(['accept_policy', 'adult']);
    expect(User::query()->where('email', 'novo@cliente.com.br')->exists())->toBeFalse();

    $this->withHeader('User-Agent', 'Navegador de teste')->post('/register', [
        'name' => 'Novo', 'email' => 'novo@cliente.com.br', 'password' => 'Senha-forte-123!', 'password_confirmation' => 'Senha-forte-123!',
        'accept_policy' => '1', 'adult' => '1',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'novo@cliente.com.br')->sole();
    $preferences = PrivacyPreference::query()->findOrFail($user->id);
    expect($preferences->policy_version)->toBe(1)
        ->and($preferences->adult_confirmed_at)->not->toBeNull()
        // Promotions only with a box the person ticks: not ticked, no consent.
        ->and($preferences->marketing)->toBeFalse()
        ->and(PrivacyConsent::query()->where('user_id', $user->id)->pluck('granted', 'purpose')->all())
        ->toBe(['policy' => true, 'adult' => true, 'marketing' => false])
        ->and(PrivacyConsent::query()->where('user_id', $user->id)->where('purpose', 'policy')->value('user_agent'))->toBe('Navegador de teste')
        ->and(PrivacyConsent::query()->where('user_id', $user->id)->where('purpose', 'policy')->value('ip_address'))->toBe('127.0.0.1');
});

it('records the consent to promotions when the person ticks it at sign-up', function () {
    privacyOwner();
    auth()->forgetUser();
    privacyOpenSignUp();

    $this->post('/register', [
        'name' => 'Quer promo', 'email' => 'promo@cliente.com.br', 'password' => 'Senha-forte-123!', 'password_confirmation' => 'Senha-forte-123!',
        'accept_policy' => '1', 'adult' => '1', 'marketing' => '1',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'promo@cliente.com.br')->sole();
    expect(Privacy::wantsMarketing($user))->toBeTrue()
        ->and(PrivacyConsent::query()->where('user_id', $user->id)->where('purpose', 'marketing')->value('granted'))->toBeTrue();
});

it('does not ask the first account, the owner of the installation, to accept its own policy', function () {
    privacyOpenSignUp();
    expect(User::query()->count())->toBe(0);
    // Coolify creates the root team with the first account.
    Team::query()->whereKey(0)->delete();

    // The sign-up page of Coolify declares a function, so it renders once per process: the first
    // account is checked through the sign-up itself.
    $this->post('/register', ['name' => 'Dona', 'email' => 'dona@hostrema.com.br', 'password' => 'Senha-forte-123!', 'password_confirmation' => 'Senha-forte-123!'])
        ->assertSessionHasNoErrors();

    $root = User::query()->where('email', 'dona@hostrema.com.br')->sole();
    expect($root->id)->toBe(0)
        ->and(PrivacyConsent::query()->count())->toBe(0)
        ->and(Privacy::needsPolicyAcceptance($root))->toBeFalse()
        ->and(Privacy::needsAdultConfirmation($root))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Public privacy policy and terms (art. 9º, 41)
// ---------------------------------------------------------------------------------------------

it('publishes a privacy policy with everything art. 9 asks for', function () {
    config(['platform.billing.provider' => 'efi', 'platform.enforcement.suspend_after_days' => 7, 'platform.enforcement.hard_suspend_after_days' => 30]);

    $this->get(route('platform.legal.privacy'))->assertOk()
        // Controller, contact and the officer (arts. 9º, III and IV; 41, § 1º).
        ->assertSee('Hostrema Tecnologia Ltda.')->assertSee('CNPJ 12.345.678/0001-90')->assertSee('Av. Paulista, 1000')
        ->assertSee('Maria Encarregada')->assertSee('dpo@hostrema.com.br')->assertSee('privacidade@hostrema.com.br')
        // Purposes, legal bases and retention (art. 9º, I and II).
        ->assertSee('Performance of the contract (art. 7º, V)')->assertSee('Consent (art. 7º, I), withdrawn at any time')
        ->assertSee('12 meses')->assertSee('5 anos')
        // Sharing (art. 9º, V), with the payment provider in use.
        ->assertSee('Efí S.A. (Efí Bank)')
        // Every right of art. 18, the ANPD and the consumer bodies.
        ->assertSee('a portabilidade dos dados a outro fornecedor')->assertSee('a revogação do consentimento')
        ->assertSee('Agência Nacional de Proteção de Dados')->assertSee('defesa do consumidor')
        // Automated decisions and their criteria (art. 20).
        ->assertSee('vencida há 7 dias')->assertSee('há 30 dias tem os containers parados')
        ->assertSee('maiores de 18 anos')->assertSee('três dias úteis');
});

it('publishes the terms of use and links both pages from the pricing page', function () {
    $this->get(route('platform.legal.terms'))->assertOk()
        ->assertSee('Termos de Uso')->assertSee('Hostrema Tecnologia Ltda.')->assertSee(route('platform.legal.privacy'), false);
    $this->get(route('platform.pricing'))->assertOk()
        ->assertSee(route('platform.legal.privacy'), false)->assertSee(route('platform.legal.terms'), false);
});

it('shows no legal pages when the platform is off', function () {
    config(['platform.enabled' => false]);

    $this->get(route('platform.legal.privacy'))->assertNotFound();
    $this->get(route('platform.legal.terms'))->assertNotFound();
});

it('uses the support e-mail as the channel when there is no privacy contact', function () {
    config(['platform.privacy.contact_email' => null, 'platform.privacy.dpo_email' => null, 'platform.privacy.dpo_name' => null, 'platform.privacy.controller_name' => null, 'platform.privacy.controller_document' => null]);

    expect(Privacy::contactEmail())->toBe('suporte@hostrema.com.br')
        ->and(Privacy::controllerName())->toBe('Hostrema')
        ->and(Privacy::missingIdentification())->toBe(['Legal name of the controller', 'CNPJ or CPF of the controller']);
    $this->get(route('platform.legal.privacy'))->assertOk()->assertSee('suporte@hostrema.com.br')->assertDontSee('encarregado pelo tratamento');
});

// ---------------------------------------------------------------------------------------------
// Privacy and data: the page of the data subject (arts. 18 and 19)
// ---------------------------------------------------------------------------------------------

it('keeps the privacy page for signed-in users only', function () {
    auth()->forgetUser();

    $this->get(route('platform.privacy'))->assertRedirect(route('login'));
    $this->get(route('platform.privacy.export'))->assertRedirect(route('login'));
});

it('opens the privacy pages to any account, paying or not, onboarded or not', function () {
    // A new customer: no plan paid yet and the onboarding not finished.
    $customer = privacyCustomer();
    expect(isSubscriptionActive())->toBeFalse()->and($customer['team']->show_boarding)->toBeTrue();

    $this->get(route('platform.privacy'))->assertOk()->assertSee('Delete my account');
    $this->get(route('platform.privacy.export'))->assertOk()->assertDownload();
    $this->get(route('platform.legal.privacy'))->assertOk();
    $this->get(route('platform.legal.terms'))->assertOk();
    // Everything else still asks for the plan.
    $this->get(route('project.index'))->assertRedirect(route('subscription.index'));
});

it('opens the privacy pages to accounts that did not confirm the e-mail yet', function () {
    $customer = privacyCustomer();
    $customer['user']->forceFill(['email_verified_at' => null])->save();

    $this->get(route('platform.privacy'))->assertOk();
    $this->get(route('platform.privacy.export'))->assertOk();
    $this->get(route('platform.legal.terms'))->assertOk();
    $this->get(route('project.index'))->assertRedirect(route('verify.email'));
});

it('lets anyone read the policy and the terms before turning on the two factors', function () {
    config(['platform.security.require_two_factor' => 'all']);
    privacyCustomer();

    $this->get(route('platform.legal.privacy'))->assertOk();
    $this->get(route('platform.legal.terms'))->assertOk();
    $this->get(route('platform.privacy'))->assertRedirect(route('profile'));
});

it('shows the data of the user at once, with the document masked', function () {
    $customer = privacyCustomer();
    privacyInvoice($customer['team']);

    Livewire::test(PrivacyPage::class)
        ->assertSee('cliente@loja.com.br')->assertSee('Loja do Cliente')->assertSee('•••••••8909')->assertDontSee('12345678909')
        ->assertSee('Invoices')->assertSee('Download a complete copy (JSON)')
        ->assertSee('Delete my account');
});

it('gives a complete copy of the data in JSON, only of the user, and records it', function () {
    $other = privacyCustomer('outro@cliente.com.br');
    privacyInvoice($other['team']);
    $customer = privacyCustomer();
    privacyInvoice($customer['team']);
    Privacy::setMarketing($customer['user'], true);
    AuditLog::query()->create(['user_id' => $customer['user']->id, 'action' => 'auth.login', 'ip_address' => '200.1.2.3', 'actor_type' => 'user']);

    $response = $this->get(route('platform.privacy.export'))->assertOk();
    $data = json_decode($response->streamedContent(), true);

    expect($data['account']['email'])->toBe('cliente@loja.com.br')
        ->and($data['controller']['name'])->toBe('Hostrema Tecnologia Ltda.')
        ->and($data['privacy']['marketing'])->toBeTrue()
        ->and($data['privacy']['consents'][0]['purpose'])->toBe('marketing')
        ->and($data['teams'])->toHaveCount(1)
        ->and($data['teams'][0]['payer']['document'])->toBe('12345678909')
        ->and($data['teams'][0]['invoices'])->toHaveCount(1)
        ->and($data['access_records'][0]['ip_address'])->toBe('200.1.2.3')
        ->and(json_encode($data))->not->toContain('outro@cliente.com.br')
        ->and(AuditLog::query()->where('action', 'privacy.data.exported')->where('user_id', $customer['user']->id)->exists())->toBeTrue();
});

it('shows payer data and invoices in the copy only to whom manages the team', function () {
    $customer = privacyCustomer();
    $member = User::factory()->create();
    $customer['team']->members()->attach($member->id, ['role' => 'member']);

    $data = PrivacyExport::forUser($member);
    $team = collect($data['teams'])->firstWhere('name', $customer['team']->name);

    expect($team['payer'])->toBeNull()->and($team['invoices'])->toBeNull()->and($team['role'])->toBe('member');
});

it('lets the user give and withdraw the consent to promotions, with proof, and hides them without it', function () {
    $customer = privacyCustomer();
    $promotion = PlatformNotification::factory()->create(['kind' => PlatformNotification::KIND_PROMOTION, 'title' => 'Black Friday']);
    $news = PlatformNotification::factory()->create(['kind' => PlatformNotification::KIND_NEWS, 'title' => 'Novidade']);
    $update = PlatformNotification::factory()->create(['kind' => PlatformNotification::KIND_UPDATE, 'title' => 'Manutenção concluída']);
    $titles = fn () => PlatformInbox::for($customer['user']->fresh(), $customer['team'])->pluck('title')->all();

    expect($titles())->toBe(['Manutenção concluída'])
        ->and(PlatformInbox::reach(PlatformNotification::AUDIENCE_ALL, null, null, PlatformNotification::KIND_PROMOTION))->toBe(0)
        ->and(PlatformInbox::reach(PlatformNotification::AUDIENCE_ALL, null, null, PlatformNotification::KIND_UPDATE))->toBe(1);

    Livewire::test(PrivacyPage::class)->set('marketing', true)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'You will receive promotions and news.');
    expect($titles())->toEqualCanonicalizing(['Black Friday', 'Novidade', 'Manutenção concluída'])
        ->and(PlatformInbox::reach(PlatformNotification::AUDIENCE_CUSTOMERS, null, null, PlatformNotification::KIND_NEWS))->toBe(1);

    Livewire::test(PrivacyPage::class)->assertSet('marketing', true)->set('marketing', false)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'You will no longer receive promotions and news.');
    expect($titles())->toBe(['Manutenção concluída'])
        ->and(PrivacyConsent::query()->where('user_id', $customer['user']->id)->where('purpose', 'marketing')->orderBy('id')->pluck('granted')->all())->toBe([true, false]);
});

it('opens a request, answered in 15 days, and tells the controller at once', function () {
    privacyOwner();
    $customer = privacyCustomer();
    Carbon::setTestNow('2026-11-23 10:00:00');

    Livewire::test(PrivacyPage::class)
        ->set('requestType', 'correction')->set('requestMessage', 'Meu CPF está errado.')
        ->call('openRequest')->assertHasNoErrors()
        ->assertDispatched('success', fn (string $event, array $params) => $params[1] === 'You will receive the answer by e-mail by 08/12/2026.')
        ->assertSee('Meu CPF está errado.')->assertSee('Answer by 08/12/2026');

    $request = PrivacyRequest::query()->sole();
    expect($request->type)->toBe('correction')->and($request->email)->toBe('cliente@loja.com.br')
        ->and($request->due_at->toDateString())->toBe('2026-12-08')->and($request->isOpen())->toBeTrue();
    // Operators see it in the bell; the controller gets an e-mail.
    expect(PlatformNotification::query()->where('audience', 'operators')->where('title', 'like', 'New LGPD request%')->exists())->toBeTrue();
    Queue::assertPushed(PlatformPrivacyNoticeJob::class, fn ($job) => $job->emails === ['privacidade@hostrema.com.br', 'dpo@hostrema.com.br']);
    Carbon::setTestNow();
});

it('refuses requests without what is needed, and too many open at once', function () {
    $customer = privacyCustomer();

    Livewire::test(PrivacyPage::class)->set('requestType', 'other')->set('requestMessage', '')->call('openRequest')->assertHasErrors(['requestMessage']);
    Livewire::test(PrivacyPage::class)->set('requestType', 'nada')->call('openRequest')->assertHasErrors(['requestType']);
    foreach (range(1, Privacy::MAX_OPEN_REQUESTS) as $index) {
        Privacy::openRequest($customer['user'], 'access', "Pedido {$index}");
    }
    Livewire::test(PrivacyPage::class)->set('requestType', 'access')->call('openRequest')->assertHasErrors(['requestType']);
    expect(PrivacyRequest::query()->count())->toBe(Privacy::MAX_OPEN_REQUESTS);
});

it('opens the page ready to ask for the review of an automated decision', function () {
    privacyCustomer();

    Livewire::withQueryParams(['pedido' => 'review'])->test(PrivacyPage::class)->assertSet('requestType', 'review');
    // Every right has its kind of request, with the article it comes from.
    Livewire::test(PrivacyPage::class)->assertSee('Oppose a processing that does not follow the law')
        ->set('requestType', 'opposition')->set('requestMessage', 'Não quero os registros de acesso além do prazo.')->call('openRequest')->assertHasNoErrors();
    expect(PrivacyRequest::query()->sole())->type->toBe('opposition')
        ->and(PrivacyRequest::typeArticle('opposition'))->toBe('art. 18, § 2º');
    Livewire::withQueryParams(['pedido' => 'qualquer'])->test(PrivacyPage::class)->assertSet('requestType', 'access');
});

it('offers the review of a suspension in the banner of the blocked account', function () {
    $customer = privacyCustomer();
    $customer['tenant']->update(['status' => PlatformTenant::STATUS_SUSPENDED, 'suspended_at' => now(), 'suspension_reason' => 'Fatura vencida']);

    // Blocked, the account still reaches the privacy page, where the banner offers the review.
    $this->get(route('platform.privacy'))->assertOk()->assertSee(route('platform.privacy', ['pedido' => 'review']), false);
});

// ---------------------------------------------------------------------------------------------
// Answers of the controller (owner console)
// ---------------------------------------------------------------------------------------------

it('lets the owner answer a request, which reaches the user by e-mail', function () {
    $customer = privacyCustomer();
    $request = Privacy::openRequest($customer['user'], 'access', 'Quero meus dados.');
    $owner = privacyOwner();

    Livewire::test(AdminPrivacy::class)
        ->assertSee('Quero meus dados.')->assertSee('1 open request.')
        ->call('startAnswer', $request->id)
        ->set('answer', 'Segue a cópia completa em anexo.')
        ->call('sendAnswer')->assertHasNoErrors()
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Answer sent to cliente@loja.com.br.');

    expect($request->fresh())->status->toBe('answered')->answer->toBe('Segue a cópia completa em anexo.')->answered_by->toBe($owner->id);
    Queue::assertPushed(PlatformPrivacyNoticeJob::class, fn ($job) => $job->emails === ['cliente@loja.com.br'] && in_array('Segue a cópia completa em anexo.', $job->lines, true));
    expect(AuditLog::query()->where('action', 'privacy.request.answered')->exists())->toBeTrue();
});

it('marks requests past 15 days as late', function () {
    PrivacyRequest::factory()->create(['due_at' => now()->subDay()]);
    PrivacyRequest::factory()->create();
    privacyOwner();

    Livewire::test(AdminPrivacy::class)->assertSee('2 open requests.')->assertSee('1 is late (15 days, art. 19).');
});

it('lets admins of the root team read the privacy tab but only the owner act, and keeps customers out', function () {
    $customer = privacyCustomer();
    $request = Privacy::openRequest($customer['user'], 'access', null);
    privacyOwner('admin');

    Livewire::test(AdminPrivacy::class)->assertOk()
        ->set('answeringId', $request->id)->set('answer', 'Oi')->call('sendAnswer')
        ->assertDispatched('error', fn (string $event, array $params) => $params[0] === 'Only the owner of the root team can do this.')
        ->set('policyChanges', 'Algo')->call('publishPolicy')
        ->assertDispatched('error');
    expect($request->fresh()->isOpen())->toBeTrue()->and(Privacy::policyVersion())->toBe(1);

    privacyCustomer('mais@cliente.com.br');
    Livewire::test(AdminPrivacy::class)->assertForbidden();
});

// ---------------------------------------------------------------------------------------------
// Deletion of the account (art. 18, VI; arts. 15 and 16)
// ---------------------------------------------------------------------------------------------

it('deletes the account the user asks for, closing its team and keeping only what the law requires', function () {
    $customer = privacyCustomer();
    $paid = privacyInvoice($customer['team']);
    $draft = privacyInvoice($customer['team'], PlatformInvoice::STATUS_DRAFT);
    $application = privacyApplication($customer['team']);
    Privacy::setMarketing($customer['user'], true);
    $customer['user']->createToken('ci');
    DB::table('sessions')->insert(['id' => 'sessao-do-cliente', 'user_id' => $customer['user']->id, 'payload' => '', 'last_activity' => time()]);

    Livewire::test(PrivacyPage::class)
        ->set('deletePassword', 'senha-errada')->set('deleteConfirmation', 'EXCLUIR')->call('deleteAccount')->assertHasErrors(['deletePassword'])
        ->set('deletePassword', 'senha-do-cliente')->set('deleteConfirmation', 'apagar')->call('deleteAccount')->assertHasErrors(['deleteConfirmation'])
        ->set('deleteConfirmation', 'delete')->call('deleteAccount')->assertHasNoErrors()->assertRedirect(route('login'));

    $record = PrivacyDeletedAccount::query()->sole();
    $user = $customer['user']->fresh();
    // At once: no session, no token, no password that works.
    expect($record)->status->toBe('pending')->requested_by->toBe('self')->email->toBe('cliente@loja.com.br')
        ->and($record->retain_until->isSameDay(now()->addMonths(12)))->toBeTrue()
        ->and(Hash::check('senha-do-cliente', $user->password))->toBeFalse()
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(auth()->check())->toBeFalse();
    Queue::assertPushed(PlatformAccountDeletionJob::class, fn ($job) => $job->recordId === $record->id);
    Queue::assertPushed(PlatformPrivacyNoticeJob::class, fn ($job) => $job->emails === ['cliente@loja.com.br'] && $job->subject === 'Your account was deleted'
        && collect($job->lines)->contains(fn ($line) => str_contains((string) $line, 'Kept for 5 years')));

    $record = runDeletion($record);

    Bus::assertDispatchedSync(DeleteResourceJob::class, fn ($job) => $job->resource->is($application));
    expect($record)->status->toBe('done')->completed_at->not->toBeNull()
        ->and($record->summary)->toMatchArray(['teams_closed' => 1, 'workloads' => 1, 'invoices_kept' => 1, 'invoices_cancelled' => 0])
        ->and(User::query()->find($customer['user']->id))->toBeNull()
        ->and(Team::query()->find($customer['team']->id))->toBeNull()
        ->and(PlatformTenant::query()->where('team_id', $customer['team']->id)->exists())->toBeFalse()
        ->and(PrivacyPreference::query()->find($customer['user']->id))->toBeNull()
        ->and(PlatformInvoice::query()->find($draft->id))->toBeNull();
    // Tax law: the paid invoice stays with whom it charged, encrypted, for five years.
    $paid = $paid->fresh();
    expect($paid->team_id)->toBeNull()
        ->and($paid->payer)->toMatchArray(['name' => 'Loja do Cliente', 'document' => '12345678909'])
        ->and($paid->retain_until->isSameDay(now()->addYears(5)))->toBeTrue()
        ->and((string) DB::table('platform_invoices')->where('id', $paid->id)->value('payer'))->not->toContain('12345678909')
        ->and($paid->toArray())->not->toHaveKey('payer')
        // The proof of the consents stays (art. 8º, § 2º).
        ->and(PrivacyConsent::query()->where('user_id', $customer['user']->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'privacy.account.deleted')->exists())->toBeTrue();
});

it('keeps an account with open invoices until they are paid, but lets the owner delete it', function () {
    $customer = privacyCustomer();
    $open = privacyInvoice($customer['team'], PlatformInvoice::STATUS_OPEN);

    Livewire::test(PrivacyPage::class)->assertSee('There is 1 open invoice. Pay it (or talk to us) before deleting the account.')
        ->assertDontSee('Your password');
    expect(fn () => AccountDeletion::request($customer['user'], PrivacyDeletedAccount::BY_SELF))->toThrow(RuntimeException::class);

    privacyOwner();
    Livewire::test(AdminPrivacy::class)
        ->set('deleteEmail', 'Cliente@Loja.com.br')->set('deleteReason', 'Pedido por e-mail')->call('deleteAccount')->assertHasNoErrors()
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Deleting the account of cliente@loja.com.br.');
    $record = PrivacyDeletedAccount::query()->sole();
    expect($record->requested_by)->toBe('owner')->and($record->reason)->toBe('Pedido por e-mail');
    Queue::assertPushed(PlatformPrivacyNoticeJob::class, fn ($job) => $job->emails === ['cliente@loja.com.br'] && str_contains($job->lines[0], 'Reason: Pedido por e-mail'));

    runDeletion($record);
    expect($open->fresh())->status->toBe(PlatformInvoice::STATUS_VOID)->team_id->toBeNull();
});

it('leaves shared teams with the other people when an account is deleted', function () {
    $customer = privacyCustomer();
    $admin = User::factory()->create();
    $customer['team']->members()->attach($admin->id, ['role' => 'admin']);
    $other = privacyCustomer('dono@outro.com.br');
    $member = User::factory()->create();
    $other['team']->members()->attach($member->id, ['role' => 'member']);
    $application = privacyApplication($customer['team']);

    $preview = AccountDeletion::preview($customer['user']);
    expect($preview['close'])->toBe([])->and($preview['leave'])->toHaveCount(1);

    runDeletion(AccountDeletion::request($customer['user'], PrivacyDeletedAccount::BY_OWNER));
    runDeletion(AccountDeletion::request($other['user'], PrivacyDeletedAccount::BY_OWNER));

    Bus::assertNotDispatchedSync(DeleteResourceJob::class);
    expect(Team::query()->find($customer['team']->id))->not->toBeNull()
        ->and(Application::query()->find($application->id))->not->toBeNull()
        ->and($customer['team']->members()->pluck('users.id')->all())->toBe([$admin->id])
        // Nobody else could own the team: the member becomes the owner.
        ->and($other['team']->members()->first()->pivot->role)->toBe('owner')
        ->and(User::query()->whereKey([$customer['user']->id, $other['user']->id])->count())->toBe(0);
});

it('never deletes the first account or the only owner of the platform', function () {
    $root = privacyRootUser();
    Team::find(0)->members()->updateExistingPivot(0, ['role' => 'admin']);
    $owner = privacyOwner();

    expect(AccountDeletion::preview($root)['blockers'])->toContain('The first account of the installation can not be deleted.')
        ->and(AccountDeletion::preview($owner)['blockers'])->toContain('This is the only owner of the platform: make another operator the owner first.');

    Livewire::test(AdminPrivacy::class)->set('deleteEmail', $owner->email)->set('deleteReason', 'x')->call('deleteAccount')
        ->assertHasErrors(['deleteEmail']);
    Livewire::test(AdminPrivacy::class)->set('deleteEmail', 'ninguem@nada.com')->set('deleteReason', 'x')->call('deleteAccount')
        ->assertHasErrors(['deleteEmail']);
    expect(PrivacyDeletedAccount::query()->count())->toBe(0);
});

it('lets the owner delete an operator of the platform, who then leaves the root team', function () {
    privacyOwner();
    $operator = User::factory()->create(['email' => 'operador@hostrema.com.br']);
    Team::find(0)->members()->attach($operator->id, ['role' => 'admin']);

    Livewire::test(AdminPrivacy::class)->set('deleteEmail', 'operador@hostrema.com.br')->set('deleteReason', 'Saiu da empresa')->call('deleteAccount')
        ->assertHasNoErrors();
    runDeletion(PrivacyDeletedAccount::query()->sole());

    expect(User::query()->find($operator->id))->toBeNull()->and(Team::find(0)->members()->count())->toBe(1);
});

it('lets the owner delete a member of a customer from the tenants tab, and nobody else', function () {
    $customer = privacyCustomer();
    privacyOwner('admin');
    Livewire::test(Admin::class)->call('deleteUser', $customer['tenant']->id, $customer['user']->id)
        ->assertDispatched('error', fn (string $event, array $params) => $params[0] === 'Only the owner of the root team can do this.');
    expect(PrivacyDeletedAccount::query()->count())->toBe(0);

    privacyOwner();
    Livewire::test(Admin::class)->set('tab', 'tenants')->call('editTenant', $customer['tenant']->id)->assertSee('Delete account')
        ->call('deleteUser', $customer['tenant']->id, $customer['user']->id)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Deleting the account of cliente@loja.com.br.');
    expect(PrivacyDeletedAccount::query()->sole())->requested_by->toBe('owner')->user_id->toBe($customer['user']->id);
});

it('blocks the sign-in of an account being deleted', function () {
    $customer = privacyCustomer();
    PrivacyDeletedAccount::query()->create(['user_id' => $customer['user']->id, 'email' => $customer['user']->email, 'requested_by' => 'self', 'retain_until' => now()->addYear()]);
    auth()->forgetUser();
    Event::fake([PlatformNotificationPublished::class]);

    auth()->login($customer['user']);
    event(new Login('web', $customer['user'], false));

    expect(auth()->check())->toBeFalse();
});

it('stops a deletion that can not finish, and the owner tries again', function () {
    $owner = privacyOwner();
    $operator = User::factory()->create();
    Team::find(0)->members()->attach($operator->id, ['role' => 'owner']);
    $record = AccountDeletion::request($operator, PrivacyDeletedAccount::BY_OWNER, $owner);
    // Meanwhile it became the only owner of the platform.
    Team::find(0)->members()->updateExistingPivot($owner->id, ['role' => 'admin']);

    $record = runDeletion($record);
    expect($record->status)->toBe('failed')->and($record->error)->toContain('only owner of the platform')
        ->and(User::query()->find($operator->id))->not->toBeNull();

    Team::find(0)->members()->updateExistingPivot($owner->id, ['role' => 'owner']);
    $this->actingAs($owner->fresh());
    session(['currentTeam' => Team::find(0)]);
    Livewire::test(AdminPrivacy::class)->assertSee('Failed')->call('retryDeletion', $record->id)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Trying the deletion again.');
    expect($record->fresh()->status)->toBe('pending');
    runDeletion($record->fresh());
    expect($record->fresh()->status)->toBe('done')->and(User::query()->find($operator->id))->toBeNull();
});

it('finishes the record when the account is already gone', function () {
    $record = PrivacyDeletedAccount::query()->create(['user_id' => 999999, 'email' => 'sumiu@x.com', 'requested_by' => 'owner', 'retain_until' => now()->addYear()]);

    expect(runDeletion($record)->status)->toBe('done');
});

it('deletes the customer at Stripe before anything else, so the card is not charged again', function () {
    config(['subscription.stripe_api_key' => 'sk_test_hostrema']);
    $customer = privacyCustomer();
    Subscription::create(['team_id' => $customer['team']->id, 'stripe_customer_id' => 'cus_cliente', 'stripe_subscription_id' => 'sub_cliente', 'stripe_invoice_paid' => true]);
    $stripe = privacyStripe(['DELETE /v1/customers/cus_cliente' => [200, ['id' => 'cus_cliente', 'object' => 'customer', 'deleted' => true]]]);

    expect(AccountDeletion::preview($customer['user'])['payment_customers'])->toBe(1);
    Livewire::test(PrivacyPage::class)->assertSee('At Stripe, the subscription is cancelled and your customer record is deleted, so the card is not charged again.');
    $record = runDeletion(AccountDeletion::request($customer['user'], PrivacyDeletedAccount::BY_SELF));

    expect($record->status)->toBe('done')->and($record->summary['payment_customers_deleted'])->toBe(1)
        ->and(collect($stripe->requests)->pluck('key')->all())->toBe(['DELETE /v1/customers/cus_cliente'])
        ->and(Subscription::query()->where('team_id', $customer['team']->id)->exists())->toBeFalse();
    Queue::assertPushed(PlatformPrivacyNoticeJob::class, fn ($job) => in_array('At Stripe, your subscription is cancelled and your customer record is deleted, so your card is not charged again.', $job->lines, true));
});

it('stops without deleting anything when Stripe fails, and goes on when tried again', function () {
    config(['subscription.stripe_api_key' => 'sk_test_hostrema']);
    $customer = privacyCustomer();
    $application = privacyApplication($customer['team']);
    Subscription::create(['team_id' => $customer['team']->id, 'stripe_customer_id' => 'cus_cliente', 'stripe_invoice_paid' => true]);
    privacyStripe(['DELETE /v1/customers/cus_cliente' => [500, ['error' => ['type' => 'api_error', 'message' => 'Stripe fora do ar']]]]);

    $record = runDeletion(AccountDeletion::request($customer['user'], PrivacyDeletedAccount::BY_SELF));

    expect($record->status)->toBe('failed')->and($record->error)->toContain('Stripe fora do ar')
        ->and(User::query()->find($customer['user']->id))->not->toBeNull()
        ->and(Application::query()->find($application->id))->not->toBeNull()
        ->and(Subscription::query()->where('team_id', $customer['team']->id)->exists())->toBeTrue();
    Bus::assertNotDispatchedSync(DeleteResourceJob::class);

    // Deleted meanwhile in the Stripe dashboard: the second try goes on.
    privacyStripe();
    AccountDeletion::retry($record);
    expect(runDeletion($record->fresh())->status)->toBe('done')
        ->and(User::query()->find($customer['user']->id))->toBeNull();
    Bus::assertDispatchedSync(DeleteResourceJob::class);
});

it('does not delete an account with a customer at Stripe while Stripe is not configured', function () {
    config(['subscription.stripe_api_key' => null]);
    $customer = privacyCustomer();
    Subscription::create(['team_id' => $customer['team']->id, 'stripe_customer_id' => 'cus_cliente', 'stripe_invoice_paid' => true]);

    $record = runDeletion(AccountDeletion::request($customer['user'], PrivacyDeletedAccount::BY_OWNER));

    expect($record->status)->toBe('failed')->and($record->error)->toContain('Stripe is not configured')
        ->and(User::query()->find($customer['user']->id))->not->toBeNull();
});

it('keeps an account with an overdue payment at Stripe until it is paid, but lets the owner delete it', function () {
    $customer = privacyCustomer();
    $customer['tenant']->update(['past_due_since' => now()->subDays(3)]);

    expect(AccountDeletion::preview($customer['user'])['blockers'])->toBe(['There is an overdue payment. Pay it (or talk to us) before deleting the account.'])
        ->and(AccountDeletion::preview($customer['user'], true)['blockers'])->toBe([]);
});

it('hands the payer contact of a shared team to the next person in charge, here and at Stripe', function () {
    config(['subscription.stripe_api_key' => 'sk_test_hostrema']);
    $customer = privacyCustomer();
    $customer['tenant']->update(['billing_profile' => array_merge($customer['tenant']->billing_profile, ['email' => 'Cliente@Loja.com.br'])]);
    $partner = User::factory()->create(['email' => 'socio@loja.com.br']);
    $customer['team']->members()->attach($partner->id, ['role' => 'member']);
    Subscription::create(['team_id' => $customer['team']->id, 'stripe_customer_id' => 'cus_loja', 'stripe_invoice_paid' => true]);
    $stripe = privacyStripe([
        'GET /v1/customers/cus_loja' => [200, ['id' => 'cus_loja', 'object' => 'customer', 'email' => 'cliente@loja.com.br']],
        'POST /v1/customers/cus_loja' => fn (array $params) => [200, ['id' => 'cus_loja', 'object' => 'customer', 'email' => $params['email']]],
    ]);

    $record = runDeletion(AccountDeletion::request($customer['user'], PrivacyDeletedAccount::BY_SELF));

    $profile = $customer['tenant']->fresh()->billing_profile;
    expect($record->status)->toBe('done')->and($record->summary['payer_contacts_replaced'])->toBe(1)
        ->and($profile['email'])->toBe('socio@loja.com.br')->and($profile['document'])->toBe('12345678909')
        ->and(collect($stripe->requests)->firstWhere('key', 'POST /v1/customers/cus_loja')['params'])->toBe(['email' => 'socio@loja.com.br'])
        // The team and its subscription stay, with the partner as the owner.
        ->and(Subscription::query()->where('team_id', $customer['team']->id)->exists())->toBeTrue()
        ->and($customer['team']->members()->sole()->pivot->role)->toBe('owner');
    Queue::assertPushed(PlatformNoticeJob::class, fn ($job) => $job->team->is($customer['team']) && $job->subject === 'Check the payer data'
        && $job->lines[0] === 'Cliente is no longer in the team '.$customer['team']->name.'.');
    expect(PlatformNotification::query()->where('audience', 'team')->where('team_id', $customer['team']->id)->where('title', 'Check the payer data')->exists())->toBeTrue();
});

it('keeps the payer contact of a shared team that is someone else', function () {
    config(['subscription.stripe_api_key' => 'sk_test_hostrema']);
    $customer = privacyCustomer();
    $partner = User::factory()->create();
    $customer['team']->members()->attach($partner->id, ['role' => 'admin']);
    Subscription::create(['team_id' => $customer['team']->id, 'stripe_customer_id' => 'cus_loja', 'stripe_invoice_paid' => true]);
    $stripe = privacyStripe(['GET /v1/customers/cus_loja' => [200, ['id' => 'cus_loja', 'object' => 'customer', 'email' => 'financeiro@loja.com.br']]]);

    $record = runDeletion(AccountDeletion::request($customer['user'], PrivacyDeletedAccount::BY_SELF));

    expect($record->summary['payer_contacts_replaced'])->toBe(0)
        ->and($customer['tenant']->fresh()->billing_profile['email'])->toBe('financeiro@loja.com.br')
        ->and(collect($stripe->requests)->pluck('key')->all())->toBe(['GET /v1/customers/cus_loja'])
        ->and(StripeBilling::replaceCustomerEmail(Team::factory()->create(), 'a@b.com', 'c@d.com'))->toBeFalse();
    Queue::assertNotPushed(PlatformNoticeJob::class);
});

// ---------------------------------------------------------------------------------------------
// Retention (arts. 15 and 16)
// ---------------------------------------------------------------------------------------------

it('deletes every day what passed its period, and keeps what has not', function () {
    $user = User::factory()->create();
    $old = AuditLog::query()->create(['action' => 'auth.login', 'actor_type' => 'user', 'user_id' => $user->id]);
    privacyAge($old, now()->subMonths(13));
    $recent = AuditLog::query()->create(['action' => 'auth.login', 'actor_type' => 'user', 'user_id' => $user->id]);
    $expiredInvoice = PlatformInvoice::create(['team_id' => null, 'type' => 'subscription', 'period_start' => now()->subYears(6), 'period_end' => now()->subYears(6), 'amount_cents' => 1, 'currency' => 'brl', 'status' => 'paid', 'lines' => [], 'retain_until' => now()->subDay()]);
    $keptInvoice = PlatformInvoice::create(['team_id' => null, 'type' => 'subscription', 'period_start' => now()->subYear(), 'period_end' => now()->subYear(), 'amount_cents' => 1, 'currency' => 'brl', 'status' => 'paid', 'lines' => [], 'retain_until' => now()->addYear()]);
    $doneRecord = PrivacyDeletedAccount::query()->create(['user_id' => 501, 'email' => 'velho@x.com', 'requested_by' => 'self', 'status' => 'done', 'retain_until' => now()->subDay()]);
    $keptRecord = PrivacyDeletedAccount::query()->create(['user_id' => 502, 'email' => 'novo@x.com', 'requested_by' => 'self', 'status' => 'done', 'retain_until' => now()->addMonth()]);
    $oldConsent = PrivacyConsent::query()->create(['user_id' => 503, 'email' => 'saiu@x.com', 'purpose' => 'policy', 'granted' => true]);
    $oldConsent->forceFill(['created_at' => now()->subYears(6)])->save();
    $livingConsent = PrivacyConsent::query()->create(['user_id' => $user->id, 'purpose' => 'policy', 'granted' => true]);
    $livingConsent->forceFill(['created_at' => now()->subYears(6)])->save();
    $oldRequest = PrivacyRequest::factory()->answered()->create(['answered_at' => now()->subYears(6)]);
    $openRequest = PrivacyRequest::factory()->create();

    $deleted = PrivacyRetention::run();

    expect(AuditLog::query()->find($old->id))->toBeNull()->and(AuditLog::query()->find($recent->id))->not->toBeNull()
        ->and(PlatformInvoice::query()->find($expiredInvoice->id))->toBeNull()->and(PlatformInvoice::query()->find($keptInvoice->id))->not->toBeNull()
        ->and(PrivacyDeletedAccount::query()->find($doneRecord->id))->toBeNull()->and(PrivacyDeletedAccount::query()->find($keptRecord->id))->not->toBeNull()
        ->and(PrivacyConsent::query()->find($oldConsent->id))->toBeNull()->and(PrivacyConsent::query()->find($livingConsent->id))->not->toBeNull()
        ->and(PrivacyRequest::query()->find($oldRequest->id))->toBeNull()->and(PrivacyRequest::query()->find($openRequest->id))->not->toBeNull()
        ->and($deleted)->toMatchArray(['invoices' => 1, 'deleted_accounts' => 1, 'consents' => 1, 'requests' => 1]);
});

it('keeps access records at least six months, whatever is configured', function () {
    config(['platform.privacy.audit_months' => 2]);
    $log = AuditLog::query()->create(['action' => 'auth.login', 'actor_type' => 'user']);
    privacyAge($log, now()->subMonths(5));
    $older = AuditLog::query()->create(['action' => 'auth.login', 'actor_type' => 'user']);
    privacyAge($older, now()->subMonths(7));

    PrivacyRetention::run();

    expect(AuditLog::query()->find($log->id))->not->toBeNull()->and(AuditLog::query()->find($older->id))->toBeNull()
        ->and(AccountDeletion::accessRecordMonths())->toBe(6);
});

it('deletes sign-ups that never confirmed the e-mail, quietly, and nobody else', function () {
    $stale = User::factory()->create(['email_verified_at' => null, 'created_at' => now()->subDays(31)]);
    $fresh = User::factory()->create(['email_verified_at' => null, 'created_at' => now()->subDays(5)]);
    $verified = User::factory()->create(['created_at' => now()->subDays(90)]);
    $operator = User::factory()->create(['email_verified_at' => null, 'created_at' => now()->subDays(90)]);
    Team::find(0)->members()->attach($operator->id, ['role' => 'admin']);

    expect(PrivacyRetention::run()['unverified_accounts'])->toBe(1);

    $record = PrivacyDeletedAccount::query()->sole();
    expect($record->user_id)->toBe($stale->id)->and($record->requested_by)->toBe('retention')
        ->and(PrivacyRetention::run()['unverified_accounts'])->toBe(0);
    // An address never confirmed may belong to someone else: no e-mail.
    Queue::assertNotPushed(PlatformPrivacyNoticeJob::class);
    runDeletion($record);
    expect(User::query()->whereKey([$stale->id, $fresh->id, $verified->id, $operator->id])->count())->toBe(3);
});

it('runs the retention with the daily maintenance', function () {
    config(['platform.privacy.unverified_days' => 7]);
    User::factory()->create(['email_verified_at' => null, 'created_at' => now()->subDays(10)]);

    (new PlatformDailyMaintenanceJob)->handle();

    expect(PrivacyDeletedAccount::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Security incidents (art. 48; Resolução CD/ANPD nº 15/2024)
// ---------------------------------------------------------------------------------------------

it('counts three business days to communicate an incident', function (string $known, string $deadline) {
    $incident = PrivacyIncident::factory()->make(['known_at' => Carbon::parse($known)]);

    expect($incident->communicationDeadline()->toDateTimeString())->toBe($deadline);
})->with([
    'monday' => ['2026-11-23 10:00:00', '2026-11-26 23:59:59'],
    'friday' => ['2026-11-27 18:00:00', '2026-12-02 23:59:59'],
    'saturday' => ['2026-11-28 09:00:00', '2026-12-02 23:59:59'],
]);

it('counts the business days in the calendar of the installation', function () {
    InstanceSettings::query()->whereKey(0)->update(['instance_timezone' => 'America/Sao_Paulo']);
    // Thursday 22:00 in São Paulo is already Friday in UTC.
    $incident = PrivacyIncident::factory()->make(['known_at' => Carbon::parse('2026-11-26 22:00:00', 'America/Sao_Paulo')->utc()]);

    $deadline = $incident->communicationDeadline();

    expect($deadline->timezoneName)->toBe('America/Sao_Paulo')
        ->and($deadline->toDateTimeString())->toBe('2026-12-01 23:59:59')
        ->and($incident->communicationDeadline('UTC')->toDateTimeString())->toBe('2026-12-02 23:59:59');
});

it('records an incident, communicates the data subjects with what the law asks and records the ANPD protocol', function () {
    $customer = privacyCustomer();
    $operator = User::factory()->create(['email' => 'operador@hostrema.com.br']);
    Team::find(0)->members()->attach($operator->id, ['role' => 'admin']);
    privacyOwner();
    InstanceSettings::query()->whereKey(0)->update(['instance_timezone' => 'America/Sao_Paulo']);

    Livewire::test(AdminPrivacy::class)
        ->set('incident.title', 'Backup exposto')
        ->set('incident.known_at', '2026-11-23T09:00')
        ->set('incident.description', 'Um backup ficou público por 2 horas.')
        ->set('incident.data_categories', 'Nome e e-mail')
        ->set('incident.affected_count', '120')
        ->set('incident.relevant_risk', true)
        ->set('incident.risks', 'Phishing')
        ->set('incident.security_measures', 'Senhas com hash')
        ->set('incident.mitigation', 'Acesso fechado e chaves trocadas')
        ->set('incident.recommendations', 'Desconfie de e-mails pedindo senha')
        ->call('saveIncident')->assertHasNoErrors()
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Incident recorded.' && $params[1] === 'Communicate the ANPD and the data subjects by 26/11/2026.')
        ->assertSee('Incident "Backup exposto": communicate the ANPD and the data subjects by 26/11/2026.');

    $incident = PrivacyIncident::query()->sole();
    expect($incident->known_at->toDateTimeString())->toBe('2026-11-23 12:00:00')->and($incident->pendingCommunication())->toBeTrue();

    // Operators have personal teams too: they are customers as well, as in the bell.
    Livewire::test(AdminPrivacy::class)->set('communicateAudience', 'customers')->call('communicate', $incident->id)
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === '3 users were told by e-mail and in the bell.');
    Queue::assertPushed(PlatformPrivacyNoticeJob::class, function ($job) {
        $text = implode("\n", $job->lines);

        return in_array('cliente@loja.com.br', $job->emails, true) && count($job->emails) === 3 && $job->subject === 'Security incident: Backup exposto'
            && str_contains($text, 'Um backup ficou público') && str_contains($text, 'Nome e e-mail') && str_contains($text, 'Phishing')
            && str_contains($text, 'Senhas com hash') && str_contains($text, 'Acesso fechado') && str_contains($text, 'Desconfie') && str_contains($text, 'dpo@hostrema.com.br');
    });
    expect(PlatformNotification::query()->where('audience', 'customers')->where('title', 'Security incident: Backup exposto')->exists())->toBeTrue()
        ->and($incident->fresh())->communicated_audience->toBe('customers')->titulares_communicated_count->toBe(3);

    Livewire::test(AdminPrivacy::class)->set('anpdIncidentId', $incident->id)->set('anpdDate', '2026-11-24')->set('anpdProtocol', 'CIS-2026-123')
        ->call('recordAnpd')->assertHasNoErrors();
    expect($incident->fresh())->anpd_protocol->toBe('CIS-2026-123')->pendingCommunication()->toBeFalse()
        ->and(AuditLog::query()->whereIn('action', ['privacy.incident.saved', 'privacy.incident.communicated', 'privacy.incident.anpd'])->count())->toBe(3);
});

it('refuses an incident without what the register needs, and keeps admins from recording it', function () {
    privacyOwner();
    Livewire::test(AdminPrivacy::class)->set('incident.title', '')->set('incident.description', '')->call('saveIncident')
        ->assertHasErrors(['incident.title', 'incident.description', 'incident.data_categories']);

    privacyOwner('admin');
    Livewire::test(AdminPrivacy::class)->set('incident.title', 'X')->set('incident.description', 'Y')->set('incident.data_categories', 'Z')->call('saveIncident')
        ->assertDispatched('error');
    expect(PrivacyIncident::query()->count())->toBe(0);
});

it('chooses who is told about an incident, as the bell does', function () {
    $customer = privacyCustomer();
    // An operator with a personal team is also a customer; one without it is only an operator.
    $operator = User::factory()->create();
    Team::find(0)->members()->attach($operator->id, ['role' => 'admin']);
    $staff = User::factory()->create();
    $staff->teams()->first()->delete();
    Team::find(0)->members()->attach($staff->id, ['role' => 'member']);

    expect(PrivacyIncidents::audience('customers')->pluck('id')->sort()->values()->all())->toBe(collect([$customer['user']->id, $operator->id])->sort()->values()->all())
        ->and(PrivacyIncidents::audience('operators')->pluck('id')->sort()->values()->all())->toBe(collect([$operator->id, $staff->id])->sort()->values()->all())
        ->and(PrivacyIncidents::audience('all')->count())->toBe(3)
        ->and(PrivacyIncidents::audience('customers')->count())->toBe(PlatformInbox::reach(PlatformNotification::AUDIENCE_CUSTOMERS, null, null, PlatformNotification::KIND_ALERT));
});

// ---------------------------------------------------------------------------------------------
// New versions of the policy (art. 8º, § 6º)
// ---------------------------------------------------------------------------------------------

it('asks every user to accept a new version of the policy, with what changed', function () {
    $customer = privacyCustomer();
    Privacy::onRegistered($customer['user'], false);
    Livewire::test(PrivacyNotice::class)->assertDontSee('Please read and accept');

    $owner = privacyOwner();
    Privacy::onRegistered($owner, false);
    Livewire::test(AdminPrivacy::class)->set('policyChanges', 'Agora usamos o Amazon SES para os e-mails.')->call('publishPolicy')
        ->assertDispatched('success', fn (string $event, array $params) => $params[0] === 'Version 2 published.');
    expect(Privacy::policyVersion())->toBe(2)
        ->and(PlatformNotification::query()->where('audience', 'all')->where('title', 'We updated the privacy policy and the terms of use')->value('body'))->toBe('Agora usamos o Amazon SES para os e-mails.');

    $this->actingAs($customer['user']);
    session(['currentTeam' => $customer['team']]);
    $this->get(route('platform.privacy'))->assertSeeLivewire(PrivacyNotice::class);
    Livewire::test(PrivacyNotice::class)
        ->assertSee('Please read and accept the privacy policy and the terms of use (version 2).')
        ->assertSee('Agora usamos o Amazon SES')
        // The choices about promotions and the age were made at sign-up: not asked again.
        ->assertDontSee('I want to receive promotions and news.')->assertDontSee('I am 18 or older.')
        ->call('accept')->assertDontSee('Please read and accept');
    expect(Privacy::preferences($customer['user'])->policy_version)->toBe(2)
        ->and(Privacy::wantsMarketing($customer['user']))->toBeFalse()
        ->and(PrivacyConsent::query()->where('user_id', $customer['user']->id)->where('purpose', 'policy')->where('policy_version', 2)->exists())->toBeTrue();
});

it('asks users from before the LGPD page to accept the policy, to declare the age and to choose about promotions', function () {
    $customer = privacyCustomer();

    Livewire::test(PrivacyNotice::class)->assertSee('version 1')->assertSee('I am 18 or older.')->assertSee('I want to receive promotions and news.')
        ->set('marketing', true)->call('accept')->assertHasErrors(['adult']);
    expect(Privacy::needsPolicyAcceptance($customer['user']))->toBeTrue()->and(PrivacyConsent::query()->count())->toBe(0);

    Livewire::test(PrivacyNotice::class)->set('marketing', true)->set('adult', true)->call('accept')->assertHasNoErrors()
        ->assertDontSee('Please read and accept');

    expect(Privacy::needsPolicyAcceptance($customer['user']))->toBeFalse()
        ->and(Privacy::needsAdultConfirmation($customer['user']))->toBeFalse()
        ->and(Privacy::wantsMarketing($customer['user']))->toBeTrue()
        ->and(PrivacyConsent::query()->where('user_id', $customer['user']->id)->pluck('source', 'purpose')->all())
        ->toEqualCanonicalizing(['policy' => 'notice', 'adult' => 'notice', 'marketing' => 'notice']);
});

it('asks only for the age when the policy was accepted without it', function () {
    $customer = privacyCustomer();
    Privacy::acceptPolicy($customer['user'], false);

    Livewire::test(PrivacyNotice::class)->assertSee('I am 18 or older.')->assertDontSee('I want to receive promotions and news.')
        ->set('adult', true)->call('accept')->assertHasNoErrors()->assertDontSee('I am 18 or older.');
    expect(Privacy::preferences($customer['user'])->adult_confirmed_at)->not->toBeNull();
});

it('never asks the first account, the controller itself, to accept its own policy', function () {
    $root = privacyRootUser();
    $this->actingAs($root);
    session(['currentTeam' => Team::find(0)]);

    Livewire::test(PrivacyNotice::class)->assertDontSee('Please read and accept')->assertDontSee('I am 18 or older.');
});

// ---------------------------------------------------------------------------------------------
// Record of the processing operations (art. 37)
// ---------------------------------------------------------------------------------------------

it('keeps the record of the processing operations, with the legal bases, and downloads it', function () {
    config(['platform.billing.provider' => 'efi']);
    privacyOwner('admin');

    $operations = collect(ProcessingRecord::operations());
    expect($operations)->toHaveCount(8)
        ->and($operations->pluck('basis')->implode(' '))->toContain('art. 7º, V')->toContain('art. 7º, II')->toContain('art. 7º, I)')->toContain('art. 7º, IX')
        ->and($operations->firstWhere('operation', 'Billing')['sharing'])->toContain('Efí S.A. (Efí Bank)')
        ->and($operations->firstWhere('operation', 'Access records and audit log')['retention'])->toBe('12 months');

    Livewire::test(AdminPrivacy::class)->assertSee('Record of the processing operations')->assertSee('Performance of the contract (art. 7º, V)')
        ->call('downloadRecord')->assertFileDownloaded('registro-das-operacoes-de-tratamento.csv');
    expect(ProcessingRecord::csv())->toStartWith('Operation,Data,"Data subjects",Purpose,"Legal basis","Shared with",Retention,Security');
});

it('warns the owner of what the policy is missing', function () {
    config(['platform.privacy.controller_document' => null]);
    privacyOwner();

    Livewire::test(AdminPrivacy::class)->assertSee('Missing in Settings → Privacy (LGPD): CNPJ or CPF of the controller.');
});

it('shows the privacy settings in the settings tab', function () {
    privacyOwner();

    $this->get(route('admin.platform', ['tab' => 'settings']))->assertOk()->assertSee('Privacy (LGPD)');
    Livewire::test(\App\Livewire\Platform\AdminSettings::class)->call('selectGroup', 'privacy')
        ->assertSee('Data protection officer (encarregado)')->assertSee('Months to keep access records and the audit log');
});
