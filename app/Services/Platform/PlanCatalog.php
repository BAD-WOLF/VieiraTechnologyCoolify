<?php

namespace App\Services\Platform;

use App\Models\PlatformPlan;
use App\Models\PlatformTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The plans of the platform: created, edited and removed by the owner in the operator console
 * (table platform_plans, applied over config('platform.plans') like the other console settings;
 * config/platform.php only seeds the first ones).
 *
 * QUOTAS and FEATURES describe what a plan can hold: every limit and capability the platform
 * enforces, with the explanation shown to customers next to it on the pricing pages.
 */
class PlanCatalog
{
    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    /**
     * Limits of a plan. "title": name in the forms; "label": written after the value on the pricing
     * pages; "unlimited": 0 means no limit (otherwise the quota must be positive); "public": shown
     * on the pricing pages; "format": how the value is written.
     *
     * @var array<string, array{title: string, label: string, help: string, format: string, unlimited: bool, public: bool, min: float|int, max: float|int}>
     */
    public const QUOTAS = [
        'cpus' => [
            'title' => 'vCPU', 'label' => 'vCPU', 'format' => 'decimal', 'unlimited' => false, 'public' => true, 'min' => 0.05, 'max' => 512,
            'help' => 'Processing power for all your containers together. On each server they share it: a container can use all of it while the others are idle, and together they never get more than the plan.',
        ],
        'memory_mb' => [
            'title' => 'Memory (MB)', 'label' => 'memory', 'format' => 'mb', 'unlimited' => false, 'public' => true, 'min' => 64, 'max' => 4194304,
            'help' => 'Memory for all your containers together on each server. A container that needs more than what is left is restarted.',
        ],
        'storage_gb' => [
            'title' => 'Storage (GB)', 'label' => 'storage', 'format' => 'gb', 'unlimited' => true, 'public' => true, 'min' => 0, 'max' => 1000000,
            'help' => 'Persistent volumes, project directories and local database backups. Above it, new deployments pause until you free space or change plans, and the extra space is billed per GB per month.',
        ],
        'resources' => [
            'title' => 'Applications, databases and services', 'label' => 'applications, databases or services', 'format' => 'count', 'unlimited' => true, 'public' => true, 'min' => 0, 'max' => 1000000,
            'help' => 'How many applications, databases and services you can create. Projects and environments are unlimited.',
        ],
        'containers' => [
            'title' => 'Containers running at the same time', 'label' => 'containers running at the same time', 'format' => 'count', 'unlimited' => true, 'public' => true, 'min' => 0, 'max' => 1000000,
            'help' => 'Each service of a Docker Compose stack, each database and each preview deployment is a container.',
        ],
        'deployments_per_day' => [
            'title' => 'Deployments per day', 'label' => 'deployments per day', 'format' => 'count', 'unlimited' => true, 'public' => true, 'min' => 0, 'max' => 1000000,
            'help' => 'Deployments in the last 24 hours, counting the automatic ones started by Git pushes.',
        ],
        'custom_domains' => [
            'title' => 'Custom domains', 'label' => 'custom domains', 'format' => 'count', 'unlimited' => true, 'public' => true, 'min' => 0, 'max' => 1000000,
            'help' => 'Domains of your own (www.yourcompany.com). The addresses the platform gives your applications are always included.',
        ],
        'egress_gb' => [
            'title' => 'Outbound traffic per month (GB)', 'label' => 'outbound traffic per month', 'format' => 'gb', 'unlimited' => true, 'public' => true, 'min' => 0, 'max' => 100000000,
            'help' => 'Data your applications send to the internet in each billing period. Above it, the extra traffic is billed per GB on the next invoice.',
        ],
        'bandwidth_mbps' => [
            'title' => 'Bandwidth (Mbit/s)', 'label' => 'bandwidth', 'format' => 'mbit', 'unlimited' => true, 'public' => true, 'min' => 0, 'max' => 1000000,
            'help' => 'Top speed of the outbound traffic of all your containers together.',
        ],
        'disk_read_mbps' => [
            'title' => 'Disk read (MB/s)', 'label' => 'disk read', 'format' => 'mbyte', 'unlimited' => true, 'public' => false, 'min' => 0, 'max' => 1000000,
            'help' => 'Top read speed of the disks for all your containers together on each server.',
        ],
        'disk_write_mbps' => [
            'title' => 'Disk write (MB/s)', 'label' => 'disk write', 'format' => 'mbyte', 'unlimited' => true, 'public' => false, 'min' => 0, 'max' => 1000000,
            'help' => 'Top write speed of the disks for all your containers together on each server.',
        ],
        'disk_iops' => [
            'title' => 'Disk operations per second', 'label' => 'disk operations per second', 'format' => 'count', 'unlimited' => true, 'public' => false, 'min' => 0, 'max' => 100000000,
            'help' => 'Disk operations per second for all your containers together on each server.',
        ],
        'pids_per_container' => [
            'title' => 'Processes per container', 'label' => 'processes per container', 'format' => 'count', 'unlimited' => false, 'public' => false, 'min' => 32, 'max' => 1000000,
            'help' => 'Processes and threads each container can run.',
        ],
    ];

    /**
     * Capabilities the platform turns on or off per plan.
     *
     * @var array<string, array{label: string, help: string}>
     */
    public const FEATURES = [
        'databases' => [
            'label' => 'Databases',
            'help' => 'PostgreSQL, MySQL, MariaDB, MongoDB, Redis, KeyDB, Dragonfly and ClickHouse, created in one click.',
        ],
        'docker_compose' => [
            'label' => 'Docker Compose and one-click services',
            'help' => 'Deploy the docker-compose.yml of your repository and services from the catalog.',
        ],
        'scheduled_backups' => [
            'label' => 'Database backups',
            'help' => 'Backups of your databases on demand and on a schedule, kept on the platform (counted in your storage) and, if you want, sent to your own S3 storage.',
        ],
        'preview_deployments' => [
            'label' => 'Preview deployments',
            'help' => 'A temporary copy of your application for each pull request, at its own address.',
        ],
        'public_databases' => [
            'label' => 'Public databases',
            'help' => 'Connect to your databases from outside the platform (your computer, other services) through a public port.',
        ],
        'log_export' => [
            'label' => 'Log export (log drains)',
            'help' => 'Send the logs of your applications and databases to an external service.',
        ],
        'traces' => [
            'label' => 'Application traces (OpenTelemetry)',
            'help' => 'Send traces from your applications and follow each request in the dashboard.',
        ],
        'dedicated_server' => [
            'label' => 'Dedicated servers (VM isolation)',
            'help' => 'Your workloads run on servers reserved for your account, isolated in their own virtual machines.',
        ],
        'sso' => [
            'label' => 'SSO (SAML/OIDC)',
            'help' => 'Sign in with the identity provider of your company, set up with our team.',
        ],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $plans = (array) config('platform.plans', []);
        $order = array_flip(array_keys($plans));
        uksort($plans, fn ($a, $b) => [(int) data_get($plans[$a], 'position', 0), $order[$a]] <=> [(int) data_get($plans[$b], 'position', 0), $order[$b]]);

        return $plans;
    }

    /**
     * Plans listed on the pricing pages and open to new subscriptions.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function public(): array
    {
        return array_filter(self::all(), fn (array $plan) => self::isPublic($plan));
    }

    /**
     * Refusal of a capability the plan of the customer does not include, naming the plans on
     * sale that include it (the owner can rename plans and change what each one has).
     */
    public static function missingFeature(string $feature): string
    {
        $label = __(self::FEATURES[$feature]['label'] ?? $feature);
        $plans = collect(self::public())
            ->filter(fn (array $plan) => (bool) data_get($plan, "features.{$feature}"))
            ->map(fn (array $plan) => (string) ($plan['name'] ?? ''))
            ->filter()
            ->values();

        return $plans->isEmpty()
            ? __('":feature" is not part of your plan. Talk to the support to get it.', ['feature' => $label])
            : __('":feature" is not part of your plan. Plans that include it: :plans.', ['feature' => $label, 'plans' => $plans->implode(', ')]);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public static function isPublic(array $plan): bool
    {
        return (bool) ($plan['public'] ?? true);
    }

    public static function exists(?string $plan): bool
    {
        return filled($plan) && array_key_exists($plan, self::all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(?string $plan): ?array
    {
        if (! self::exists($plan)) {
            return null;
        }

        return self::all()[$plan];
    }

    /**
     * Plans saved in the console, applied over config('platform.plans') (see PlatformConfig).
     */
    public static function load(): void
    {
        try {
            if (! Schema::hasTable('platform_plans')) {
                return;
            }
            $plans = PlatformPlan::query()->orderBy('position')->orderBy('id')->get();
        } catch (\Throwable) {
            return;
        }
        if ($plans->isEmpty()) {
            return;
        }
        config(['platform.plans' => $plans->mapWithKeys(fn (PlatformPlan $plan) => [$plan->slug => $plan->toCatalogEntry()])->all()]);
    }

    /**
     * @return array{cpus: float, memory_mb: int, storage_gb: int, resources: int, containers: int, deployments_per_day: int, custom_domains: int, egress_gb: int, pids_per_container: int}
     */
    public static function emptyQuotas(): array
    {
        return [
            'cpus' => 0.0,
            'memory_mb' => 0,
            'storage_gb' => 0,
            'resources' => 0,
            'containers' => 0,
            'deployments_per_day' => 0,
            'custom_domains' => 0,
            'egress_gb' => 0,
            'pids_per_container' => 256,
        ];
    }

    /**
     * Price of a plan in cents, null when the plan has none for the interval.
     */
    public static function priceCents(?string $plan, ?string $interval): ?int
    {
        if (! self::exists($plan)) {
            return null;
        }
        $interval = $interval === self::INTERVAL_YEARLY ? self::INTERVAL_YEARLY : self::INTERVAL_MONTHLY;
        $value = data_get(self::get($plan), "price_{$interval}");
        if (blank($value)) {
            return null;
        }
        $cents = (int) round(((float) str_replace(',', '.', (string) $value)) * 100);

        return $cents > 0 ? $cents : null;
    }

    public static function tenantsOn(string $plan): int
    {
        return PlatformTenant::query()->where('plan', $plan)->orWhere('pending_plan', $plan)->count();
    }

    /**
     * Limits of a plan as shown on the pricing pages: value, label and explanation.
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, array{key: string, value: string, label: string, help: string}>
     */
    public static function quotaLines(array $plan): array
    {
        $lines = [];
        foreach (self::QUOTAS as $key => $definition) {
            if (! $definition['public'] || ! array_key_exists($key, (array) ($plan['quotas'] ?? []))) {
                continue;
            }
            $value = (float) $plan['quotas'][$key];
            $lines[] = [
                'key' => $key,
                'value' => self::formatQuota($key, $value),
                'label' => __($definition['label']),
                'help' => self::quotaHelp($key),
            ];
        }

        return $lines;
    }

    public static function formatQuota(string $key, float|int $value): string
    {
        $definition = self::QUOTAS[$key] ?? ['format' => 'count', 'unlimited' => false];
        if ($definition['unlimited'] && $value <= 0) {
            return __('Unlimited');
        }
        [$point, $thousands] = str_starts_with((string) app()->getLocale(), 'pt') ? [',', '.'] : ['.', ','];
        $number = fn (float $number, int $decimals = 2) => $decimals === 0
            ? number_format($number, 0, $point, $thousands)
            : rtrim(rtrim(number_format($number, $decimals, $point, $thousands), '0'), $point);

        return match ($definition['format']) {
            'decimal' => $number((float) $value),
            'mb' => $value >= 1024 ? $number($value / 1024, 1).' GB' : $number((float) $value, 0).' MB',
            'gb' => $number((float) $value, 0).' GB',
            'mbit' => $number((float) $value, 0).' Mbit/s',
            'mbyte' => $number((float) $value, 0).' MB/s',
            default => $number((float) $value, 0),
        };
    }

    public static function quotaHelp(string $key): string
    {
        $help = __(self::QUOTAS[$key]['help'] ?? '');
        $rates = (array) config('platform.overage', []);
        $extra = match ($key) {
            'storage_gb' => (int) ($rates['storage_gb_month_cents'] ?? 0) > 0
                ? __('Extra storage: :price per GB per month.', ['price' => formatPlatformMoney((int) $rates['storage_gb_month_cents'], (string) config('platform.currency', 'brl'))])
                : null,
            'egress_gb' => (int) ($rates['egress_gb_cents'] ?? 0) > 0
                ? __('Extra traffic: :price per GB.', ['price' => formatPlatformMoney((int) $rates['egress_gb_cents'], (string) config('platform.currency', 'brl'))])
                : null,
            'custom_domains' => filled(config('platform.base_domain'))
                ? __('Addresses under :domain are always included.', ['domain' => config('platform.base_domain')])
                : null,
            default => null,
        };

        return trim($help.' '.($extra ?? ''));
    }

    /**
     * Capabilities of a plan as shown on the pricing pages, included or not.
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, array{key: string, label: string, help: string, included: bool}>
     */
    public static function featureLines(array $plan): array
    {
        $lines = [];
        foreach (self::FEATURES as $key => $definition) {
            if (! array_key_exists($key, (array) ($plan['features'] ?? []))) {
                continue;
            }
            $lines[] = [
                'key' => $key,
                'label' => __($definition['label']),
                'help' => __($definition['help']),
                'included' => (bool) $plan['features'][$key],
            ];
        }

        return $lines;
    }

    /**
     * Items the owner added to a plan (support, SLA...), with their explanation.
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, array{text: string, help: string}>
     */
    public static function extraLines(array $plan): array
    {
        return array_values(array_filter(array_map(fn ($extra) => [
            'text' => trim((string) data_get($extra, 'text', is_string($extra) ? $extra : '')),
            'help' => trim((string) data_get($extra, 'help', '')),
        ], (array) ($plan['extras'] ?? [])), fn (array $extra) => $extra['text'] !== ''));
    }

    public static function priceIdFor(string $plan, string $interval): ?string
    {
        $key = $interval === self::INTERVAL_YEARLY ? 'stripe_price_yearly' : 'stripe_price_monthly';

        return data_get(self::get($plan), $key);
    }

    /**
     * Map a Stripe price id back to [plan, interval].
     *
     * @return array{0: string, 1: string}|null
     */
    public static function planForPriceId(?string $priceId): ?array
    {
        if (blank($priceId)) {
            return null;
        }
        foreach (self::all() as $key => $plan) {
            if (data_get($plan, 'stripe_price_monthly') === $priceId) {
                return [$key, self::INTERVAL_MONTHLY];
            }
            if (data_get($plan, 'stripe_price_yearly') === $priceId) {
                return [$key, self::INTERVAL_YEARLY];
            }
        }

        return null;
    }

    public static function isSelfServe(string $plan, string $interval = self::INTERVAL_MONTHLY): bool
    {
        $definition = self::get($plan);
        if (! $definition || ! self::isPublic($definition)) {
            return false;
        }

        if (data_get($definition, 'contact_sales', false)) {
            return false;
        }

        return PlatformBilling::enabled() ? ! is_null(self::priceCents($plan, $interval)) : filled(self::priceIdFor($plan, $interval));
    }

    /**
     * Human readable price for a plan (Stripe prices are read from Stripe and cached for an hour).
     */
    public static function displayPrice(string $plan, string $interval = self::INTERVAL_MONTHLY): ?string
    {
        if (PlatformBilling::enabled()) {
            $cents = self::priceCents($plan, $interval);

            return is_null($cents) ? null : formatPlatformMoney($cents, 'brl');
        }
        $priceId = self::priceIdFor($plan, $interval);
        if (blank($priceId) || blank(config('subscription.stripe_api_key'))) {
            return null;
        }

        return Cache::remember("platform:price:{$priceId}", 3600, function () use ($priceId) {
            try {
                $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));
                $price = $stripe->prices->retrieve($priceId, []);
                if (is_null($price->unit_amount)) {
                    return null;
                }

                return formatPlatformMoney($price->unit_amount, $price->currency);
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        });
    }
}
