<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;

/**
 * Read-only catalog of the platform plans defined in config/platform.php.
 */
class PlanCatalog
{
    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return (array) config('platform.plans', []);
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
        if (! $definition) {
            return false;
        }

        return ! data_get($definition, 'contact_sales', false) && filled(self::priceIdFor($plan, $interval));
    }

    /**
     * Human readable price for a plan, read from Stripe and cached for an hour.
     */
    public static function displayPrice(string $plan, string $interval = self::INTERVAL_MONTHLY): ?string
    {
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
