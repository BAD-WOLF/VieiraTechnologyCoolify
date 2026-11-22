<?php

use App\Models\InstanceSettings;
use App\Models\PlatformWebhook;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\WebhookUrlGuard;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'constants.coolify.self_hosted' => false,
        'platform.enabled' => true,
        'platform.security.require_two_factor' => 'operator',
        'platform.security.operator_api_cidrs' => [],
    ]);
    InstanceSettings::query()->firstOrCreate(['id' => 0], ['fqdn' => 'https://cloud.example.com']);
    if (! Team::query()->whereKey(0)->exists()) {
        Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    }
});

function useAppKeys(string $key, array $previous = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function operatorUser(): User
{
    $user = User::factory()->create(['name' => 'Operator']);
    Team::find(0)->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => Team::find(0)]);

    return $user;
}

it('re-encrypts every encrypted column with the new APP_KEY', function () {
    $old = 'base64:'.base64_encode(random_bytes(32));
    $new = 'base64:'.base64_encode(random_bytes(32));
    useAppKeys($old);
    WebhookUrlGuard::resolveUsing(fn () => ['93.184.216.34']);
    $team = Team::factory()->create();
    $webhook = PlatformWebhook::create(['team_id' => $team->id, 'url' => 'https://hooks.example.com', 'events' => ['*'], 'secret' => 'whsec_rotate_me']);
    WebhookUrlGuard::resolveUsing(null);
    \App\Models\PlatformSetting::put('efi.production.client_secret', 'efi-secret-rotate-me');

    useAppKeys($new, [$old]);
    $this->artisan('platform:rotate-app-key', ['--dry-run' => true])->assertSuccessful();
    $raw = DB::table('platform_webhooks')->where('id', $webhook->id)->value('secret');
    expect(fn () => (new Encrypter(base64_decode(substr($new, 7)), 'AES-256-CBC'))->decryptString($raw))->toThrow(\Illuminate\Contracts\Encryption\DecryptException::class);

    $this->artisan('platform:rotate-app-key')->assertSuccessful();
    $raw = DB::table('platform_webhooks')->where('id', $webhook->id)->value('secret');
    expect((new Encrypter(base64_decode(substr($new, 7)), 'AES-256-CBC'))->decryptString($raw))->toBe('whsec_rotate_me');

    useAppKeys($new);
    \App\Models\PlatformSetting::flushMemo();
    expect($webhook->fresh()->secret)->toBe('whsec_rotate_me')
        // Settings are encrypted by hand: no cast reveals them, and they are re-encrypted too.
        ->and(\App\Models\PlatformSetting::get('efi.production.client_secret'))->toBe('efi-secret-rotate-me')
        ->and(\App\Console\Commands\PlatformRotateAppKey::encryptedColumns())->toHaveKeys(['environment_variables', 'private_keys', 'platform_webhooks', 'platform_tenants', 'platform_settings', 'oauth_settings']);
});

it('rewrites a stale SSH key file when the key changes in the database', function () {
    Storage::fake('ssh-keys');
    $first = PrivateKey::generateNewKeyPair('ed25519');
    $second = PrivateKey::generateNewKeyPair('ed25519');
    $key = PrivateKey::create(['name' => 'nodes', 'private_key' => $first['private_key'], 'team_id' => 0]);
    $key->storeInFileSystem();
    $stored = $key->fresh()->private_key;
    expect(Storage::disk('ssh-keys')->get("ssh_key@{$key->uuid}"))->toBe($stored);

    // What PATCH /api/v1/security/keys/{uuid} does: the database changes, the file stays.
    $key->update(['private_key' => $second['private_key']]);
    expect(Storage::disk('ssh-keys')->get("ssh_key@{$key->uuid}"))->toBe($stored);

    PrivateKey::query()->select(['id', 'uuid', 'name'])->find($key->id);
    expect(Storage::disk('ssh-keys')->get("ssh_key@{$key->uuid}"))->toBe($stored);

    $current = PrivateKey::find($key->id)->private_key;
    expect(Storage::disk('ssh-keys')->get("ssh_key@{$key->uuid}"))->toBe($current)->not->toBe($stored);
});

it('requires two-factor authentication from operators', function () {
    $operator = operatorUser();
    $this->actingAs($operator);
    $this->withoutVite()->get('/admin/platform')->assertRedirect(route('profile'));
    $this->withoutVite()->get('/profile')->assertOk();

    $operator->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->withoutVite()->get('/admin/platform')->assertOk();

    $tenant = User::factory()->create();
    expect(\App\Http\Middleware\PlatformRequireTwoFactor::requiredFor($tenant))->toBeFalse();
    config(['platform.security.require_two_factor' => 'all']);
    expect(\App\Http\Middleware\PlatformRequireTwoFactor::requiredFor($tenant))->toBeTrue();
    config(['platform.security.require_two_factor' => 'none']);
    expect(\App\Http\Middleware\PlatformRequireTwoFactor::requiredFor($operator))->toBeFalse();
});

it('accepts operator API tokens only from the allowed networks', function () {
    $operator = operatorUser();
    $operatorToken = $operator->createToken('ansible', ['read'])->plainTextToken;
    $tenant = User::factory()->create();
    session(['currentTeam' => $tenant->teams()->first()]);
    $tenantToken = $tenant->createToken('ci', ['read'])->plainTextToken;
    config(['platform.security.operator_api_cidrs' => ['10.0.0.0/8']]);

    $this->getJson('/api/v1/platform/plans', ['Authorization' => 'Bearer '.$operatorToken])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->getJson('/api/v1/platform/plans', ['Authorization' => 'Bearer '.$operatorToken])->assertOk();
    app('auth')->forgetGuards();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->getJson('/api/v1/platform/plans', ['Authorization' => 'Bearer '.$tenantToken])->assertOk();
});
