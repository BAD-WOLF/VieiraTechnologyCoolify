<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Settings of the platform that the owner edits in the operator console (Settings tab).
 *
 * The files in config/ (and the .env) only provide the defaults: a value saved in the console is
 * stored encrypted in platform_settings ("config:<path>") and applied over config() on every
 * request and before every queued job, so a change reaches every instance of the control plane
 * at once. Only what the application needs to start (database, APP_KEY, PLATFORM_ENABLED,
 * cluster mode) stays in the environment.
 */
class PlatformConfig
{
    public const PREFIX = 'config:';

    /** @var array<string, string> */
    public const GROUPS = [
        'general' => 'General',
        'plans' => 'Plans',
        'billing' => 'Billing',
        'tenants' => 'Accounts',
        'containers' => 'Containers and isolation',
        'observability' => 'Logs, traces and metering',
        'integrations' => 'Webhooks and events',
        'security' => 'Security',
        'internal' => 'Internal tokens and edge',
    ];

    /** @var array<string, string> */
    public const QUOTA_LABELS = [
        'cpus' => 'CPUs',
        'memory_mb' => 'Memory (MB)',
        'storage_gb' => 'Disk (GB)',
        'resources' => 'Applications, services and databases',
        'containers' => 'Containers',
        'deployments_per_day' => 'Deployments per day (0 = unlimited)',
        'custom_domains' => 'Custom domains (0 = unlimited)',
        'egress_gb' => 'Outbound traffic per month (GB)',
        'pids_per_container' => 'Processes per container',
        'disk_read_mbps' => 'Disk read (MB/s, 0 = unlimited)',
        'disk_write_mbps' => 'Disk write (MB/s, 0 = unlimited)',
        'disk_iops' => 'Disk IOPS (0 = unlimited)',
        'bandwidth_mbps' => 'Bandwidth (Mbit/s, 0 = unlimited)',
    ];

    /** @var array<string, string> */
    public const FEATURE_LABELS = [
        'databases' => 'Databases',
        'persistent_volumes' => 'Persistent volumes',
        'scheduled_backups' => 'Scheduled backups',
        'log_export' => 'Log export (log drains)',
        'preview_deployments' => 'Preview deployments',
        'public_databases' => 'Public databases',
        'docker_compose' => 'Docker Compose',
        'dedicated_server' => 'Dedicated server',
        'sso' => 'SSO (SAML/OIDC)',
        'traces' => 'Application traces (OpenTelemetry)',
    ];

    public static bool $enabledInTests = false;

    /** True while config:cache / optimize builds the cached configuration (set by PlatformServiceProvider). */
    public static bool $buildingConfigCache = false;

    /** @var array<string, mixed> values before any override, per path */
    private static array $defaults = [];

    /** @var array<string, mixed>|null */
    private static ?array $overrides = null;

    private static ?float $appliedAt = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, array<string, mixed>> path => definition
     */
    public static function definitions(): array
    {
        if (! is_null(self::$definitions)) {
            return self::$definitions;
        }
        $runtimes = ['' => 'Docker default (runc)', 'runsc' => 'gVisor (runsc)'];
        $definitions = [
            // General
            'platform.name' => ['group' => 'general', 'label' => 'Platform name', 'type' => 'string', 'required' => true, 'help' => 'Shown in the dashboard, e-mails, invoices and the public pricing page.'],
            'platform.branding.description' => ['group' => 'general', 'label' => 'Short description', 'type' => 'text', 'help' => 'Public pricing page and page description.'],
            'platform.locale' => ['group' => 'general', 'label' => 'Language of the platform pages', 'type' => 'select', 'options' => ['pt-br' => 'Português (Brasil)', 'en' => 'English']],
            'platform.support_email' => ['group' => 'general', 'label' => 'Support e-mail', 'type' => 'email'],
            'platform.sales_url' => ['group' => 'general', 'label' => 'Sales contact URL (Enterprise)', 'type' => 'url'],
            'platform.branding.docs_url' => ['group' => 'general', 'label' => 'Documentation URL', 'type' => 'url'],
            'platform.branding.terms_url' => ['group' => 'general', 'label' => 'Terms of service URL', 'type' => 'url'],
            'platform.branding.privacy_url' => ['group' => 'general', 'label' => 'Privacy policy URL', 'type' => 'url'],
            'platform.branding.favicon_url' => ['group' => 'general', 'label' => 'Logo / favicon URL', 'type' => 'url'],
            'platform.branding.enabled' => ['group' => 'general', 'label' => 'Show the platform name instead of "Coolify" in the dashboard', 'type' => 'bool'],
            'platform.currency' => ['group' => 'general', 'label' => 'Currency', 'type' => 'select', 'options' => ['brl' => 'BRL (R$)', 'usd' => 'USD (US$)', 'eur' => 'EUR (€)']],
            'platform.base_domain' => ['group' => 'general', 'label' => 'Base domain of the applications', 'type' => 'host', 'help' => 'e.g. apps.example.com. The base domain and the reserved hosts below can never be used by customers.'],
            'platform.reserved_hosts' => ['group' => 'general', 'label' => 'Reserved hosts', 'type' => 'list', 'item' => 'host', 'help' => 'Comma separated. Names without a dot are reserved under the base domain.'],

            // Billing
            'platform.billing.provider' => ['group' => 'billing', 'label' => 'Payment provider', 'type' => 'select', 'options' => ['inter' => 'Banco Inter (boleto + Pix, automatic)', 'manual' => 'Manual (Pix or transfer confirmed by you)', 'stripe' => 'Stripe (card)']],
            'platform.billing.manual_instructions' => ['group' => 'billing', 'label' => 'Payment instructions (manual provider)', 'type' => 'text', 'help' => 'Shown on every open invoice: Pix key, bank account, whom to send the receipt to...'],
            'platform.billing.allow_simulated_payments' => ['group' => 'billing', 'label' => 'Customers can simulate the payment (tests only)', 'type' => 'bool', 'help' => 'Adds a "simulate payment" button to open invoices. Never enable it in production.'],
            'platform.billing.due_days' => ['group' => 'billing', 'label' => 'Days to pay an invoice', 'type' => 'int', 'min' => 1, 'max' => 30],
            'platform.billing.cancel_after_days' => ['group' => 'billing', 'label' => 'Days after the due date until an unpaid boleto is cancelled', 'type' => 'int', 'min' => 0, 'max' => 60],
            'platform.billing.fine_percent' => ['group' => 'billing', 'label' => 'Late fine (%)', 'type' => 'decimal', 'min' => 0, 'max' => 2],
            'platform.billing.interest_percent_month' => ['group' => 'billing', 'label' => 'Late interest (% per month)', 'type' => 'decimal', 'min' => 0, 'max' => 1],
            'platform.billing.min_charge_cents' => ['group' => 'billing', 'label' => 'Smallest charge (cents)', 'type' => 'int', 'min' => 0, 'max' => 100000],
            'platform.overage.egress_gb_cents' => ['group' => 'billing', 'label' => 'Extra outbound traffic (cents per GB)', 'type' => 'int', 'min' => 0, 'max' => 100000],
            'platform.overage.storage_gb_month_cents' => ['group' => 'billing', 'label' => 'Extra disk (cents per GB per month)', 'type' => 'int', 'min' => 0, 'max' => 100000],
            'platform.trial_days' => ['group' => 'billing', 'label' => 'Trial days (Stripe)', 'type' => 'int', 'min' => 0, 'max' => 90],
            'platform.stripe_automatic_tax' => ['group' => 'billing', 'label' => 'Stripe automatic tax', 'type' => 'bool'],
            'subscription.stripe_api_key' => ['group' => 'billing', 'label' => 'Stripe secret key', 'type' => 'secret'],
            'subscription.stripe_webhook_secret' => ['group' => 'billing', 'label' => 'Stripe webhook secret', 'type' => 'secret'],

            // Accounts
            'platform.enforcement.quota_warning_percent' => ['group' => 'tenants', 'label' => 'Warn customers at this % of a quota', 'type' => 'int', 'min' => 1, 'max' => 100],
            'platform.enforcement.suspend_after_days' => ['group' => 'tenants', 'label' => 'Suspend after this many days overdue', 'type' => 'int', 'min' => 1, 'max' => 365],
            'platform.enforcement.hard_suspend_after_days' => ['group' => 'tenants', 'label' => 'Stop every container after this many days overdue', 'type' => 'int', 'min' => 1, 'max' => 365],
            'platform.allow_tenant_servers' => ['group' => 'tenants', 'label' => 'Customers can add their own servers', 'type' => 'bool'],
            'platform.tenant_terminal' => ['group' => 'tenants', 'label' => 'Web terminal for customers', 'type' => 'bool', 'help' => 'Terminal inside the containers of the customer. Keep it off on shared nodes.'],

            // Containers and isolation
            'platform.container.runtime' => ['group' => 'containers', 'label' => 'Runtime on the shared nodes', 'type' => 'select', 'options' => $runtimes, 'help' => 'gVisor gives every container its own kernel. It must be installed on the nodes (Ansible does it).'],
            'platform.container.dedicated_runtime' => ['group' => 'containers', 'label' => 'Runtime on the dedicated VMs (Enterprise)', 'type' => 'select', 'options' => $runtimes],
            'platform.container.default_cpus' => ['group' => 'containers', 'label' => 'CPUs of a container without a limit', 'type' => 'decimal', 'min' => 0.1, 'max' => 64],
            'platform.container.default_memory_mb' => ['group' => 'containers', 'label' => 'Memory of a container without a limit (MB)', 'type' => 'int', 'min' => 64, 'max' => 262144],
            'platform.container.cap_add' => ['group' => 'containers', 'label' => 'Linux capabilities kept', 'type' => 'list', 'item' => 'capability'],
            'platform.container.oom_score_adj' => ['group' => 'containers', 'label' => 'OOM score of the customer containers', 'type' => 'int', 'min' => -1000, 'max' => 1000],
            'platform.container.tenant_slices' => ['group' => 'containers', 'label' => 'Limit each customer as a whole on every node (systemd slices)', 'type' => 'bool'],
            'platform.container.log_max_size' => ['group' => 'containers', 'label' => 'Size of each container log file', 'type' => 'size'],
            'platform.container.log_max_file' => ['group' => 'containers', 'label' => 'Log files kept per container', 'type' => 'int', 'min' => 1, 'max' => 20, 'cast' => 'string'],
            'platform.container.enforce_storage_opt' => ['group' => 'containers', 'label' => 'Disk limit per container (needs XFS with pquota)', 'type' => 'bool'],
            'platform.container.public_port_min' => ['group' => 'containers', 'label' => 'First port of public databases', 'type' => 'int', 'min' => 1024, 'max' => 65535],
            'platform.container.public_port_max' => ['group' => 'containers', 'label' => 'Last port of public databases', 'type' => 'int', 'min' => 1024, 'max' => 65535],

            // Logs, traces and metering
            'platform.logs.loki_url' => ['group' => 'observability', 'label' => 'Loki address (regions without their own)', 'type' => 'template', 'help' => '{region} is replaced by the region slug. Each region registered by Ansible already has its own.'],
            'platform.logs.timeout' => ['group' => 'observability', 'label' => 'Log history timeout (seconds)', 'type' => 'int', 'min' => 1, 'max' => 60],
            'platform.logs.max_lines' => ['group' => 'observability', 'label' => 'Lines per log search', 'type' => 'int', 'min' => 100, 'max' => 5000],
            'platform.telemetry.endpoint' => ['group' => 'observability', 'label' => 'OTLP endpoint for customer traces', 'type' => 'template', 'help' => '{region} is replaced by the region slug.'],
            'platform.metering.interval_minutes' => ['group' => 'observability', 'label' => 'Usage collection interval (minutes)', 'type' => 'int', 'min' => 1, 'max' => 60],
            'platform.metering.raw_retention_days' => ['group' => 'observability', 'label' => 'Days to keep raw usage samples', 'type' => 'int', 'min' => 7, 'max' => 3650],

            // Webhooks and events
            'platform.webhooks.max_per_team' => ['group' => 'integrations', 'label' => 'Webhooks per account', 'type' => 'int', 'min' => 0, 'max' => 100],
            'platform.webhooks.timeout' => ['group' => 'integrations', 'label' => 'Webhook timeout (seconds)', 'type' => 'int', 'min' => 1, 'max' => 30],
            'platform.webhooks.retention_days' => ['group' => 'integrations', 'label' => 'Days to keep the delivery history', 'type' => 'int', 'min' => 1, 'max' => 365],
            'platform.webhooks.disable_after_failures' => ['group' => 'integrations', 'label' => 'Disable a webhook after this many failures in a row (0 = never)', 'type' => 'int', 'min' => 0, 'max' => 1000],
            'platform.webhooks.allow_http' => ['group' => 'integrations', 'label' => 'Accept plain HTTP webhook URLs', 'type' => 'bool'],
            'platform.webhooks.operator_private_targets' => ['group' => 'integrations', 'label' => 'Operator webhooks can target private addresses', 'type' => 'bool'],
            'platform.events.stream' => ['group' => 'integrations', 'label' => 'Redis stream of the events (empty = off)', 'type' => 'string', 'pattern' => '/^[A-Za-z0-9:_.-]*$/'],
            'platform.events.stream_max_length' => ['group' => 'integrations', 'label' => 'Events kept in the stream', 'type' => 'int', 'min' => 1000, 'max' => 10000000],

            // Security
            'platform.security.require_two_factor' => ['group' => 'security', 'label' => 'Two-factor authentication required for', 'type' => 'select', 'options' => ['operator' => 'Operators (root team)', 'all' => 'Every user', 'none' => 'Nobody']],
            'platform.security.operator_api_cidrs' => ['group' => 'security', 'label' => 'Networks allowed to use operator API tokens', 'type' => 'list', 'item' => 'cidr', 'help' => 'Comma separated CIDRs (e.g. 203.0.113.0/24). Empty = any network. Include the address of the Ansible host and of your CI.'],

            // Internal tokens and edge
            'platform.metrics_token' => ['group' => 'internal', 'label' => 'Token of the Prometheus endpoint (/api/v1/platform/metrics)', 'type' => 'secret', 'generate' => true],
            'platform.edge.token' => ['group' => 'internal', 'label' => 'Token of the regional edge routers', 'type' => 'secret', 'generate' => true],
            'platform.edge.node_http_port' => ['group' => 'internal', 'label' => 'HTTP port of the node proxies', 'type' => 'int', 'min' => 1, 'max' => 65535],
            'platform.edge.node_https_port' => ['group' => 'internal', 'label' => 'HTTPS port of the node proxies', 'type' => 'int', 'min' => 1, 'max' => 65535],
            'platform.edge.proxy_protocol' => ['group' => 'internal', 'label' => 'Send PROXY protocol to the node proxies', 'type' => 'bool'],
            'platform.edge.cache_seconds' => ['group' => 'internal', 'label' => 'Edge configuration cache (seconds)', 'type' => 'int', 'min' => 1, 'max' => 300],
        ];

        foreach ((array) config('platform.plans', []) as $plan => $settings) {
            $definitions["platform.plans.{$plan}.name"] = ['group' => 'plans', 'plan' => $plan, 'section' => 'plan', 'label' => 'Name', 'type' => 'string', 'required' => true];
            $definitions["platform.plans.{$plan}.description"] = ['group' => 'plans', 'plan' => $plan, 'section' => 'plan', 'label' => 'Description', 'type' => 'text'];
            $definitions["platform.plans.{$plan}.price_monthly"] = ['group' => 'plans', 'plan' => $plan, 'section' => 'plan', 'label' => 'Monthly price', 'type' => 'money'];
            $definitions["platform.plans.{$plan}.price_yearly"] = ['group' => 'plans', 'plan' => $plan, 'section' => 'plan', 'label' => 'Yearly price', 'type' => 'money'];
            $definitions["platform.plans.{$plan}.contact_sales"] = ['group' => 'plans', 'plan' => $plan, 'section' => 'plan', 'label' => 'Sold by the sales team (no self-service)', 'type' => 'bool'];
            $definitions["platform.plans.{$plan}.stripe_price_monthly"] = ['group' => 'plans', 'plan' => $plan, 'section' => 'plan', 'label' => 'Stripe price id (monthly)', 'type' => 'string'];
            $definitions["platform.plans.{$plan}.stripe_price_yearly"] = ['group' => 'plans', 'plan' => $plan, 'section' => 'plan', 'label' => 'Stripe price id (yearly)', 'type' => 'string'];
            foreach (array_keys((array) data_get($settings, 'quotas', [])) as $quota) {
                $definitions["platform.plans.{$plan}.quotas.{$quota}"] = [
                    'group' => 'plans', 'plan' => $plan, 'section' => 'quotas', 'field' => $quota,
                    'label' => self::QUOTA_LABELS[$quota] ?? Str::headline($quota),
                    'type' => $quota === 'cpus' ? 'decimal' : 'int', 'min' => 0, 'max' => $quota === 'cpus' ? 512 : 100000000,
                ];
            }
            foreach (array_keys((array) data_get($settings, 'features', [])) as $feature) {
                $definitions["platform.plans.{$plan}.features.{$feature}"] = [
                    'group' => 'plans', 'plan' => $plan, 'section' => 'features', 'field' => $feature,
                    'label' => self::FEATURE_LABELS[$feature] ?? Str::headline($feature), 'type' => 'bool',
                ];
            }
        }

        return self::$definitions = $definitions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definition(string $path): ?array
    {
        return self::definitions()[$path] ?? null;
    }

    /**
     * Applies the saved values over config(). Called when the application boots and before every
     * queued job (at most every $maxAgeSeconds in long running workers).
     */
    public static function apply(int $maxAgeSeconds = 0): void
    {
        // With the platform off the fork behaves like Coolify: no extra query per request.
        if (! config('platform.enabled') || (app()->runningUnitTests() && ! self::$enabledInTests)) {
            return;
        }
        if ($maxAgeSeconds > 0 && ! is_null(self::$appliedAt) && microtime(true) - self::$appliedAt < $maxAgeSeconds) {
            return;
        }
        // Never bake the saved values (and secrets) into the cached configuration.
        if (self::$buildingConfigCache || app()->runningConsoleCommand('config:cache', 'optimize')) {
            return;
        }
        try {
            self::$overrides = self::load();
        } catch (\Throwable) {
            return;
        }
        self::$appliedAt = microtime(true);
        foreach (self::definitions() as $path => $definition) {
            if (! array_key_exists($path, self::$defaults)) {
                self::$defaults[$path] = config($path);
            }
            config([$path => array_key_exists($path, self::$overrides) ? self::$overrides[$path] : self::$defaults[$path]]);
        }
        if (isPlatform()) {
            self::ensureGeneratedSecrets();
            $locale = (string) config('platform.locale');
            if (in_array($locale, ['pt-br', 'en'], true)) {
                config(['app.locale' => $locale]);
                app()->setLocale($locale);
            }
            // E-mails sign with the application name ("© Coolify", "invited to ... on Coolify").
            if (PlatformBranding::enabled() && ! is_null(PlatformBranding::name())) {
                config(['app.name' => PlatformBranding::name()]);
            }
            if (! app()->runningUnitTests()) {
                self::refreshBrandedViews();
            }
        }
    }

    /**
     * Forget what was read, so the next apply() reads the database again.
     */
    public static function flush(): void
    {
        self::$overrides = null;
        self::$appliedAt = null;
        self::$definitions = null;
        self::$defaults = [];
    }

    /**
     * @return array<string, mixed> path => value
     */
    public static function overrides(): array
    {
        return self::$overrides ??= self::load();
    }

    public static function isOverridden(string $path): bool
    {
        return array_key_exists($path, self::overrides());
    }

    /**
     * Value of the configuration files / environment, without the saved one.
     */
    public static function default(string $path): mixed
    {
        return array_key_exists($path, self::$defaults) ? self::$defaults[$path] : config($path);
    }

    public static function value(string $path): mixed
    {
        return config($path);
    }

    public static function equals(string $path, mixed $a, mixed $b): bool
    {
        $definition = self::definition($path);

        return ! is_null($definition) && self::same($definition, $a, $b);
    }

    /**
     * Saves a value (already normalized). A value equal to the default removes the override.
     */
    public static function set(string $path, mixed $value): void
    {
        $definition = self::definition($path);
        if (is_null($definition)) {
            throw new \InvalidArgumentException("Unknown setting {$path}.");
        }
        if (! array_key_exists($path, self::$defaults)) {
            self::$defaults[$path] = config($path);
        }
        if (self::same($definition, $value, self::$defaults[$path])) {
            self::reset($path);

            return;
        }
        PlatformSetting::put(self::PREFIX.$path, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::$overrides = array_merge(self::overrides(), [$path => $value]);
        config([$path => $value]);
    }

    public static function reset(string $path): void
    {
        PlatformSetting::put(self::PREFIX.$path, null);
        $overrides = self::overrides();
        unset($overrides[$path]);
        self::$overrides = $overrides;
        config([$path => self::default($path)]);
    }

    /**
     * Turns a value typed in the form into the stored value; throws on invalid input.
     */
    public static function normalize(string $path, mixed $raw): mixed
    {
        $definition = self::definition($path) ?? throw new \InvalidArgumentException("Unknown setting {$path}.");
        $type = $definition['type'];
        if ($type === 'bool') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }
        $text = is_array($raw) ? implode(',', $raw) : trim((string) $raw);
        $value = match ($type) {
            'int' => (int) $text,
            'decimal' => round((float) str_replace(',', '.', $text), 4),
            'money' => $text === '' ? null : number_format((float) str_replace(',', '.', $text), 2, '.', ''),
            'list' => array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $text) ?: []), fn ($item) => $item !== ''))),
            'host' => $text === '' ? null : strtolower(preg_replace('#^https?://#i', '', rtrim($text, '/'))),
            'size' => strtolower($text),
            default => $text === '' ? null : $text,
        };
        if (($definition['cast'] ?? null) === 'string' && ! is_null($value)) {
            $value = (string) $value;
        }
        if ($type === 'list') {
            $value = match ($definition['item'] ?? null) {
                'capability' => array_map('strtoupper', $value),
                'host' => array_map('strtolower', $value),
                default => $value,
            };
            $value = array_values(array_unique($value));
            foreach ($value as $item) {
                $valid = match ($definition['item'] ?? null) {
                    'cidr' => self::isCidr($item),
                    'capability' => (bool) preg_match('/^[A-Z][A-Z0-9_]{1,31}$/', $item),
                    'host' => (bool) preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $item),
                    default => true,
                };
                if (! $valid) {
                    throw new \InvalidArgumentException(__('":item" is not valid.', ['item' => $item]));
                }
            }
        }

        return $value;
    }

    /**
     * Laravel validation rules of the raw form value.
     *
     * @return list<mixed>
     */
    public static function rules(string $path): array
    {
        $definition = self::definition($path) ?? [];
        $required = (bool) ($definition['required'] ?? false);
        $base = [$required ? 'required' : 'nullable'];

        return match ($definition['type'] ?? 'string') {
            'bool' => ['boolean'],
            'int' => ['required', 'integer', 'min:'.($definition['min'] ?? 0), 'max:'.($definition['max'] ?? PHP_INT_MAX)],
            'decimal' => ['required', 'numeric', 'min:'.($definition['min'] ?? 0), 'max:'.($definition['max'] ?? 1000000)],
            'money' => ['nullable', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'select' => ['nullable', Rule::in(array_map('strval', array_keys($definition['options'] ?? [])))],
            'email' => [...$base, 'email', 'max:255'],
            'url' => [...$base, 'url:http,https', 'max:255'],
            'template' => [...$base, 'string', 'max:255', 'regex:#^https?://[^\s]+$#'],
            'host' => [...$base, 'string', 'max:253', 'regex:#^(https?://)?[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?/?$#'],
            'size' => ['required', 'regex:/^\d{1,4}[kKmMgG]$/'],
            'text' => [...$base, 'string', 'max:2000'],
            'list' => ['nullable', 'string', 'max:2000'],
            'secret' => ['nullable', 'string', 'min:16', 'max:255'],
            default => array_values(array_filter([...$base, 'string', 'max:255', $definition['pattern'] ?? null ? 'regex:'.$definition['pattern'] : null])),
        };
    }

    /**
     * Value of a setting as shown in the form.
     */
    public static function display(string $path): mixed
    {
        $definition = self::definition($path) ?? [];
        $value = config($path);

        return match ($definition['type'] ?? 'string') {
            'bool' => (bool) $value,
            'list' => implode(', ', (array) $value),
            'secret' => '',
            default => is_null($value) ? '' : (string) $value,
        };
    }

    public static function generateSecret(string $path): string
    {
        $secret = Str::random(48);
        self::set($path, $secret);

        return $secret;
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        $values = [];
        PlatformSetting::query()->where('key', 'like', self::PREFIX.'%')->get(['key', 'value'])
            ->each(function (PlatformSetting $setting) use (&$values) {
                $path = substr((string) $setting->key, strlen(self::PREFIX));
                if (is_null(self::definition($path))) {
                    return;
                }
                $decoded = self::decrypt((string) $setting->value);
                if (! is_null($decoded)) {
                    $values[$path] = json_decode($decoded, true);
                }
            });

        return $values;
    }

    /**
     * Tokens that only the platform itself uses are created on the first start, so nothing
     * has to be set in the environment.
     */
    private static function ensureGeneratedSecrets(): void
    {
        foreach (self::definitions() as $path => $definition) {
            if (($definition['generate'] ?? false) && blank(config($path))) {
                try {
                    self::generateSecret($path);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * The platform name is written into the compiled Blade views; when the branding changes the
     * compiled views of this instance are discarded (each instance checks on its own).
     */
    private static function refreshBrandedViews(): void
    {
        $signature = PlatformBranding::signature();
        $directory = (string) config('view.compiled');
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }
        $marker = $directory.'/.platform-branding';
        if (is_file($marker) && trim((string) @file_get_contents($marker)) === $signature) {
            return;
        }
        // Marker first, so concurrent requests do not clean the directory again.
        @file_put_contents($marker, $signature);
        foreach (File::glob($directory.'/*.php') as $compiled) {
            @unlink($compiled);
        }
    }

    private static function same(array $definition, mixed $a, mixed $b): bool
    {
        $canonical = function (mixed $value) use ($definition): string {
            return match ($definition['type']) {
                'bool' => $value ? '1' : '0',
                'int' => (string) (int) $value,
                'decimal' => (string) round((float) $value, 4),
                'money' => is_null($value) || $value === '' ? '' : number_format((float) $value, 2, '.', ''),
                'list' => implode(',', (array) $value),
                default => (string) $value,
            };
        };

        return $canonical($a) === $canonical($b);
    }

    private static function isCidr(string $value): bool
    {
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, null);
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (is_null($bits)) {
            return true;
        }
        $max = str_contains($address, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max;
    }

    private static function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
