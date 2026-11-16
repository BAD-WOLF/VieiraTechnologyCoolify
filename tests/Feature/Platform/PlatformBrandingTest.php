<?php

use App\Jobs\SendMessageToDiscordJob;
use App\Jobs\SendMessageToTelegramJob;
use App\Livewire\Help;
use App\Livewire\Platform\PlatformHelp;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Dto\DiscordMessage;
use App\Services\Platform\PlatformBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class BrandingProbeComponent extends Component
{
    public function save(): void
    {
        $this->dispatch('success', 'Coolify saved your changes.', 'Open App\\Coolify\\Task in Coolify.');
    }

    public function render(): string
    {
        return '<div>probe</div>';
    }
}

beforeEach(function () {
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.name' => 'Hostrema',
        'platform.branding.enabled' => true,
        'platform.branding.favicon_url' => null,
        'platform.branding.docs_url' => null,
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0]);
    Team::query()->firstOrCreate(['id' => 0], ['name' => 'Root Team', 'personal_team' => false]);
});

afterEach(function () {
    // Views compiled with the platform name must not leak into other tests.
    Artisan::call('view:clear');
});

it('shows a logo drawn from the platform name instead of the Coolify logo', function () {
    // With no user at all, Coolify sends /login to the registration of the first one.
    User::factory()->create();
    $this->get('/platform/brand/logo.svg')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertSee('>H</text>', false);

    Artisan::call('view:clear');
    $login = $this->withoutVite()->get('/login')->assertOk();
    expect($login->getContent())->toContain('/platform/brand/logo.svg')->not->toContain('coolify-logo');

    config(['platform.branding.favicon_url' => 'https://cdn.example.com/hostrema.png']);
    expect(PlatformBranding::logoUrl())->toBe('https://cdn.example.com/hostrema.png');
});

it('writes the platform name into page titles given at runtime and into documentation links', function () {
    expect(Blade::render('<title>{{ $title ?? \'Coolify\' }}</title>', ['title' => 'Tags | Coolify']))->toBe('<title>Tags | Hostrema</title>')
        ->and(Blade::render('<title>{{ $title ?? \'Coolify\' }}</title>'))->toBe('<title>Hostrema</title>');

    config(['platform.branding.docs_url' => 'https://ajuda.hostrema.com.br/']);
    $html = Blade::render('<a href="https://coolify.io/docs/knowledge-base/health-checks">docs</a> Example: http://app.coolify.io:3000');
    expect($html)->toBe('<a href="https://ajuda.hostrema.com.br">docs</a> Example: http://app.example.com:3000');
});

it('writes the platform name into the notifications of the dashboard', function () {
    Livewire::test(BrandingProbeComponent::class)
        ->call('save')
        ->assertDispatched('success', 'Hostrema saved your changes.', 'Open App\\Coolify\\Task in Hostrema.');
});

it('writes the platform name into chat notifications', function () {
    Http::fake();

    (new SendMessageToDiscordJob(new DiscordMessage('Coolify: Test', 'This is a test Discord notification from Coolify.', DiscordMessage::infoColor()), 'https://discord.com/api/webhooks/1/abc'))->handle();
    (new SendMessageToTelegramJob("Coolify: Server 'web' revived.", [['text' => 'Open task in Coolify', 'url' => 'https://painel.example.com']], 'token', '42'))->handle();
    Http::post('https://api.example.com/hook', ['message' => 'Coolify stays here']);

    // What goes over the wire (the recorded data() is the array given to the client).
    $sent = fn (Request $request) => (array) json_decode($request->body(), true);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://discord.com/')
        && $sent($request)['embeds'][0]['title'] === 'Hostrema: Test'
        && $sent($request)['embeds'][0]['description'] === 'This is a test Discord notification from Hostrema.'
        && str_starts_with($sent($request)['embeds'][0]['footer']['text'], 'Hostrema'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.telegram.org')
        && $sent($request)['text'] === "Hostrema: Server 'web' revived."
        && str_contains((string) $sent($request)['reply_markup'], 'Open task in Hostrema'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/hook' && $sent($request)['message'] === 'Coolify stays here');
});

it('reads the deployment logs with the platform name', function () {
    $deployment = ApplicationDeploymentQueue::query()->forceCreate([
        'application_id' => '1',
        'deployment_uuid' => 'brandingtest1',
        'logs' => json_encode([['output' => "Please make sure that it is available in the image or turn off healthcheck on Coolify's UI.", 'type' => 'stdout']]),
    ]);

    $logs = ApplicationDeploymentQueue::query()->findOrFail($deployment->id)->logs;
    expect($logs)->toContain("turn off healthcheck on Hostrema's UI")->not->toContain('Coolify');
});

it('sends the feedback of a customer to the operator, never to the Coolify team', function () {
    $owner = User::factory()->create(['email' => 'owner@hostrema.com.br']);
    Team::find(0)->members()->attach($owner->id, ['role' => 'owner']);
    $customer = User::factory()->create(['name' => 'Maria', 'email' => 'maria@example.com']);
    $this->actingAs($customer);
    InstanceSettings::query()->findOrFail(0)->update(['smtp_enabled' => true, 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_from_address' => 'no-reply@hostrema.com.br', 'smtp_from_name' => 'Hostrema']);
    Http::fake();
    $sent = [];
    // Stop the message before the SMTP transport, keeping what would be sent.
    Event::listen(MessageSending::class, function (MessageSending $event) use (&$sent) {
        $sent[] = $event->message;

        return false;
    });

    expect(Livewire::new('help'))->toBeInstanceOf(PlatformHelp::class)->toBeInstanceOf(Help::class);
    Livewire::test('help')
        ->set('subject', 'Deploy travado')
        ->set('description', 'Meu deploy está parado há 10 minutos.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getTo()[0]->getAddress())->toBe('owner@hostrema.com.br')
        ->and($sent[0]->getReplyTo()[0]->getAddress())->toBe('maria@example.com')
        ->and($sent[0]->getSubject())->toBe('[Hostrema] Deploy travado')
        ->and($sent[0]->getHtmlBody())->toContain('maria@example.com')->toContain('Meu deploy está parado');
    Http::assertNothingSent();
});
