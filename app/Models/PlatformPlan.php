<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A plan of the platform, created and edited by the owner in the operator console. Tenants and
 * invoices refer to it by its slug, which never changes.
 *
 * @property array<string, float|int> $quotas
 * @property array<string, bool> $features
 * @property array<int, array{text: string, help?: string|null}> $extras
 */
class PlatformPlan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_public' => 'boolean',
            'is_highlighted' => 'boolean',
            'contact_sales' => 'boolean',
            'price_monthly_cents' => 'integer',
            'price_yearly_cents' => 'integer',
            'quotas' => 'array',
            'features' => 'array',
            'extras' => 'array',
        ];
    }

    /**
     * The plan in the shape of config('platform.plans.<slug>').
     *
     * @return array<string, mixed>
     */
    public function toCatalogEntry(): array
    {
        $price = fn (?int $cents) => $cents ? number_format($cents / 100, 2, '.', '') : null;

        return [
            'name' => $this->name,
            'description' => (string) $this->description,
            'price_monthly' => $price($this->price_monthly_cents),
            'price_yearly' => $price($this->price_yearly_cents),
            'stripe_price_monthly' => $this->stripe_price_monthly,
            'stripe_price_yearly' => $this->stripe_price_yearly,
            'contact_sales' => $this->contact_sales,
            'public' => $this->is_public,
            'highlighted' => $this->is_highlighted,
            'position' => $this->position,
            'quotas' => (array) $this->quotas,
            'features' => array_map('boolval', (array) $this->features),
            'extras' => array_values((array) $this->extras),
        ];
    }
}
