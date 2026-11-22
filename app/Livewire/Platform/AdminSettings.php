<?php

namespace App\Livewire\Platform;

use App\Models\PlatformRegion;
use App\Models\Server;
use App\Services\Platform\Efi\EfiSettings;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\PlatformConfig;
use Livewire\Component;

/**
 * Settings tab of the operator console: everything the owner can change without touching the
 * environment (see PlatformConfig). Admins of the root team can read it; only owners save.
 */
class AdminSettings extends Component
{
    public string $group = 'general';

    /** @var array<string, mixed> setting key (path with "__" instead of ".") => form value */
    public array $form = [];

    /** @var array<string, string> new values of secret settings */
    public array $secrets = [];

    public ?string $revealed = null;

    public function mount(): void
    {
        abort_unless($this->canView(), 403);
        $this->loadForm();
    }

    public static function key(string $path): string
    {
        return str_replace('.', '__', $path);
    }

    public static function path(string $key): string
    {
        return str_replace('__', '.', $key);
    }

    public function selectGroup(string $group): void
    {
        if (array_key_exists($group, PlatformConfig::GROUPS)) {
            $this->group = $group;
            $this->resetErrorBag();
            $this->revealed = null;
        }
    }

    public function save(): void
    {
        if (! $this->authorizeEdit()) {
            return;
        }
        $paths = array_keys(array_filter(PlatformConfig::definitions(), fn ($definition) => $definition['group'] === $this->group && $definition['type'] !== 'secret'));
        $rules = [];
        $attributes = [];
        foreach ($paths as $path) {
            $rules['form.'.self::key($path)] = PlatformConfig::rules($path);
            $attributes['form.'.self::key($path)] = __(PlatformConfig::definition($path)['label']);
        }
        $this->validate($rules, [], $attributes);

        $values = [];
        foreach ($paths as $path) {
            try {
                $values[$path] = PlatformConfig::normalize($path, $this->form[self::key($path)] ?? null);
            } catch (\InvalidArgumentException $e) {
                $this->addError('form.'.self::key($path), $e->getMessage());

                return;
            }
        }
        if (! $this->crossCheck($values)) {
            return;
        }
        $changed = [];
        foreach ($values as $path => $value) {
            if (! PlatformConfig::equals($path, $value, config($path))) {
                PlatformConfig::set($path, $value);
                $changed[] = $path;
            }
        }
        if ($changed !== []) {
            audit_log('platform.settings.updated', null, ['settings' => $changed], 0);
        }
        $this->loadForm();
        $this->dispatch('success', $changed === [] ? __('Nothing changed.') : __('Settings saved.'));
    }

    public function resetSetting(string $key): void
    {
        $path = self::path($key);
        if (! $this->authorizeEdit() || is_null(PlatformConfig::definition($path))) {
            return;
        }
        PlatformConfig::reset($path);
        audit_log('platform.settings.reset', null, ['setting' => $path], 0);
        $this->loadForm();
        $this->dispatch('success', __('Default value restored.'));
    }

    public function saveSecret(string $key): void
    {
        $path = self::path($key);
        $definition = PlatformConfig::definition($path);
        if (! $this->authorizeEdit() || ($definition['type'] ?? null) !== 'secret') {
            return;
        }
        $this->validate(['secrets.'.$key => ['required', ...array_slice(PlatformConfig::rules($path), 1)]], [], ['secrets.'.$key => __($definition['label'])]);
        PlatformConfig::set($path, trim((string) $this->secrets[$key]));
        unset($this->secrets[$key]);
        audit_log('platform.settings.updated', null, ['settings' => [$path]], 0);
        $this->dispatch('success', __('Settings saved.'));
    }

    public function generateSecret(string $key): void
    {
        $path = self::path($key);
        $definition = PlatformConfig::definition($path);
        if (! $this->authorizeEdit() || ($definition['type'] ?? null) !== 'secret') {
            return;
        }
        PlatformConfig::generateSecret($path);
        audit_log('platform.settings.secret_generated', null, ['setting' => $path], 0);
        $this->revealed = $key;
        $this->dispatch('success', __('New token generated. Update it wherever it is used.'));
    }

    public function reveal(string $key): void
    {
        if ($this->authorizeEdit()) {
            $this->revealed = $this->revealed === $key ? null : $key;
        }
    }

    public function render()
    {
        $definitions = array_filter(PlatformConfig::definitions(), fn ($definition) => $definition['group'] === $this->group);
        $overrides = array_keys(PlatformConfig::overrides());

        return view('livewire.platform.admin-settings', [
            'groups' => PlatformConfig::GROUPS,
            'fields' => array_filter($definitions, fn ($definition) => $definition['type'] !== 'secret'),
            'secretFields' => array_filter($definitions, fn ($definition) => $definition['type'] === 'secret'),
            'overridden' => array_flip($overrides),
            'canEdit' => $this->canEdit(),
            'checklist' => $this->group === 'general' ? $this->checklist() : [],
        ]);
    }

    /**
     * What is still missing for the platform to sell and run, with where to fix it.
     *
     * @return list<array{ok: bool, label: string, hint: string, url: ?string}>
     */
    private function checklist(): array
    {
        $settings = instanceSettings();
        $nodes = Server::query()->where('team_id', 0)->whereNotNull('platform_region_id')
            ->whereHas('settings', fn ($query) => $query->where('is_usable', true))->count();
        $payments = match (true) {
            PlatformBilling::isManual() => true,
            PlatformBilling::enabled() => EfiSettings::isConfigured(),
            default => filled(config('subscription.stripe_api_key')),
        };

        return [
            ['ok' => filled($settings->fqdn), 'label' => __('Dashboard domain'), 'hint' => __('Settings > General > Domain of the instance.'), 'url' => route('settings.index')],
            ['ok' => (bool) $settings->is_registration_enabled, 'label' => __('Customers can sign up'), 'hint' => __('Settings > Advanced > Registration allowed.'), 'url' => route('settings.advanced')],
            ['ok' => (bool) $settings->is_api_enabled, 'label' => __('API enabled'), 'hint' => __('Settings > Advanced > API access (Ansible and the customers use it).'), 'url' => route('settings.advanced')],
            ['ok' => (bool) ($settings->smtp_enabled || $settings->resend_enabled), 'label' => __('E-mail delivery'), 'hint' => __('Settings > Transactional e-mail (SMTP or Resend): invoices, verification and notices.'), 'url' => route('settings.email')],
            ['ok' => $payments, 'label' => __('Payments'), 'hint' => PlatformBilling::isManual() ? __('Manual payments: confirm them in the Billing tab.') : __('Billing tab: Efí Bank credentials.'), 'url' => route('admin.platform', ['tab' => 'billing'])],
            ['ok' => PlatformRegion::query()->where('is_active', true)->exists(), 'label' => __('Region'), 'hint' => __('Regions & nodes tab (Ansible creates it when it registers the nodes).'), 'url' => route('admin.platform', ['tab' => 'nodes'])],
            ['ok' => $nodes > 0, 'label' => __('Node ready for customers'), 'hint' => __('A validated server of the root team assigned to a region.'), 'url' => route('admin.platform', ['tab' => 'nodes'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function crossCheck(array $values): bool
    {
        $pairs = [
            ['platform.container.public_port_min', 'platform.container.public_port_max', __('The first port must be lower than the last one.')],
            ['platform.enforcement.suspend_after_days', 'platform.enforcement.hard_suspend_after_days', __('Stopping the containers must come after the suspension.')],
        ];
        foreach ($pairs as [$low, $high, $message]) {
            if (array_key_exists($low, $values) && array_key_exists($high, $values) && $values[$low] > $values[$high]) {
                $this->addError('form.'.self::key($high), $message);

                return false;
            }
        }

        return true;
    }

    private function loadForm(): void
    {
        $this->form = [];
        foreach (PlatformConfig::definitions() as $path => $definition) {
            if ($definition['type'] !== 'secret') {
                $this->form[self::key($path)] = PlatformConfig::display($path);
            }
        }
    }

    private function canView(): bool
    {
        return isPlatform() && auth()->check() && (int) currentTeam()?->id === 0 && auth()->user()->isInstanceAdmin();
    }

    private function canEdit(): bool
    {
        return $this->canView() && (bool) auth()->user()?->isOwner();
    }

    private function authorizeEdit(): bool
    {
        if (! $this->canEdit()) {
            $this->dispatch('error', __('Only the owner of the root team can change the settings.'));

            return false;
        }

        return true;
    }
}
