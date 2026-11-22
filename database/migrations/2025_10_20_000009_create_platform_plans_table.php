<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The plans move from config/platform.php to the database, where the owner creates, edits and
 * removes them in the operator console. The plans of the configuration, with what was already
 * saved in the console for them (prices, limits, features), become the first rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_highlighted')->default(false);
            $table->boolean('contact_sales')->default(false);
            $table->unsignedBigInteger('price_monthly_cents')->nullable();
            $table->unsignedBigInteger('price_yearly_cents')->nullable();
            $table->json('quotas');
            $table->json('features');
            $table->json('extras')->nullable();
            $table->string('stripe_price_monthly')->nullable();
            $table->string('stripe_price_yearly')->nullable();
            $table->timestamps();
        });

        $plans = (array) config('platform.plans', []);
        $saved = [];
        if (Schema::hasTable('platform_settings')) {
            foreach (DB::table('platform_settings')->where('key', 'like', 'config:platform.plans.%')->orWhere('key', 'like', 'price.%')->get(['key', 'value']) as $setting) {
                try {
                    $saved[(string) $setting->key] = Crypt::decryptString((string) $setting->value);
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        foreach ($saved as $key => $value) {
            if (str_starts_with($key, 'config:platform.plans.')) {
                $path = substr($key, strlen('config:platform.plans.'));
                $decoded = json_decode($value, true);
                if (Arr::has($plans, explode('.', $path)[0])) {
                    Arr::set($plans, $path, $decoded);
                }
            }
        }

        $position = 0;
        foreach ($plans as $slug => $plan) {
            $price = function (string $interval) use ($slug, $plan, $saved): ?int {
                $value = $saved["price.{$slug}.{$interval}"] ?? data_get($plan, "price_{$interval}");
                if (blank($value)) {
                    return null;
                }
                $cents = (int) round(((float) str_replace(',', '.', (string) $value)) * 100);

                return $cents > 0 ? $cents : null;
            };
            DB::table('platform_plans')->insert([
                'slug' => (string) $slug,
                'name' => (string) data_get($plan, 'name', $slug),
                'description' => data_get($plan, 'description'),
                'position' => ++$position,
                'is_public' => true,
                'is_highlighted' => $slug === 'pro',
                'contact_sales' => (bool) data_get($plan, 'contact_sales', false),
                'price_monthly_cents' => $price('monthly'),
                'price_yearly_cents' => $price('yearly'),
                'quotas' => json_encode((array) data_get($plan, 'quotas', [])),
                'features' => json_encode(array_map('boolval', array_diff_key((array) data_get($plan, 'features', []), ['persistent_volumes' => true]))),
                'extras' => json_encode([]),
                'stripe_price_monthly' => data_get($plan, 'stripe_price_monthly'),
                'stripe_price_yearly' => data_get($plan, 'stripe_price_yearly'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('platform_settings')) {
            DB::table('platform_settings')->where('key', 'like', 'config:platform.plans.%')->orWhere('key', 'like', 'price.%')->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_plans');
    }
};
