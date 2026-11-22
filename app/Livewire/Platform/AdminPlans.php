<?php

namespace App\Livewire\Platform;

use App\Models\PlatformInvoice;
use App\Models\PlatformPlan;
use App\Models\PlatformTenant;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PlatformBilling;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Plans tab of the operator console: the owner creates, edits, orders, hides and removes plans and
 * decides every limit and capability of each one. Admins of the root team can read it; only owners
 * save. Changes reach every customer on the plan within a few minutes (PlatformConfig).
 */
class AdminPlans extends Component
{
    /** Slug of the plan being edited, "new" for a new plan, null when closed. */
    public ?string $editing = null;

    /**
     * @var array{slug: string, name: string, description: string, price_monthly: string, price_yearly: string, contact_sales: bool, is_public: bool, is_highlighted: bool, stripe_price_monthly: string, stripe_price_yearly: string}
     */
    public array $plan = [];

    /** @var array<string, string> */
    public array $quotas = [];

    /** @var array<string, bool> */
    public array $unlimited = [];

    /** @var array<string, bool> */
    public array $features = [];

    /** @var array<int, array{text: string, help: string}> */
    public array $extras = [];

    public ?string $deleting = null;

    public string $replacement = '';

    public function mount(): void
    {
        abort_unless($this->canView(), 403);
    }

    public function create(?string $copyFrom = null): void
    {
        if (! $this->authorizeEdit()) {
            return;
        }
        $source = PlatformPlan::query()->where('slug', $copyFrom)->first();
        $this->loadPlan($source);
        $this->editing = 'new';
        if ($source) {
            $this->plan['name'] = __(':name (copy)', ['name' => $source->name]);
            $this->plan['is_public'] = false;
            $this->plan['is_highlighted'] = false;
        }
        $this->plan['slug'] = '';
    }

    public function edit(string $slug): void
    {
        if (! $this->authorizeEdit()) {
            return;
        }
        $this->loadPlan(PlatformPlan::query()->where('slug', $slug)->firstOrFail());
        $this->editing = $slug;
    }

    public function cancel(): void
    {
        $this->reset('editing', 'plan', 'quotas', 'unlimited', 'features', 'extras');
        $this->resetErrorBag();
    }

    public function addExtra(): void
    {
        $this->extras[] = ['text' => '', 'help' => ''];
    }

    public function removeExtra(int $index): void
    {
        unset($this->extras[$index]);
        $this->extras = array_values($this->extras);
    }

    public function save(): void
    {
        if (! $this->authorizeEdit() || is_null($this->editing)) {
            return;
        }
        $isNew = $this->editing === 'new';
        if ($isNew && blank($this->plan['slug'] ?? '')) {
            $this->plan['slug'] = Str::of((string) ($this->plan['name'] ?? ''))->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(32, '')->value();
        }
        $rules = [
            'plan.name' => ['required', 'string', 'max:60'],
            'plan.description' => ['nullable', 'string', 'max:500'],
            'plan.price_monthly' => ['nullable', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'plan.price_yearly' => ['nullable', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'plan.contact_sales' => ['boolean'],
            'plan.is_public' => ['boolean'],
            'plan.is_highlighted' => ['boolean'],
            'plan.stripe_price_monthly' => ['nullable', 'string', 'max:100'],
            'plan.stripe_price_yearly' => ['nullable', 'string', 'max:100'],
            'extras.*.text' => ['nullable', 'string', 'max:120'],
            'extras.*.help' => ['nullable', 'string', 'max:300'],
        ];
        if ($isNew) {
            $rules['plan.slug'] = ['required', 'regex:/^[a-z0-9][a-z0-9_-]{0,31}$/', 'unique:platform_plans,slug'];
        }
        $attributes = ['plan.name' => __('Name'), 'plan.slug' => __('Identifier'), 'plan.price_monthly' => __('Monthly price'), 'plan.price_yearly' => __('Yearly price')];
        foreach (PlanCatalog::QUOTAS as $key => $definition) {
            if (! ($this->unlimited[$key] ?? false)) {
                $rules["quotas.{$key}"] = ['required', 'numeric', 'min:'.$definition['min'], 'max:'.$definition['max']];
                $attributes["quotas.{$key}"] = __($definition['title']);
            }
        }
        $this->validate($rules, [], $attributes);

        $quotas = [];
        foreach (PlanCatalog::QUOTAS as $key => $definition) {
            $value = $definition['unlimited'] && ($this->unlimited[$key] ?? false) ? 0 : (float) str_replace(',', '.', (string) ($this->quotas[$key] ?? 0));
            $quotas[$key] = $definition['format'] === 'decimal' ? round($value, 3) : (int) round($value);
        }
        $features = [];
        foreach (array_keys(PlanCatalog::FEATURES) as $key) {
            $features[$key] = (bool) ($this->features[$key] ?? false);
        }
        $extras = array_values(array_filter(array_map(fn (array $extra) => [
            'text' => trim((string) ($extra['text'] ?? '')),
            'help' => trim((string) ($extra['help'] ?? '')),
        ], $this->extras), fn (array $extra) => $extra['text'] !== ''));
        $attributes = [
            'name' => trim((string) $this->plan['name']),
            'description' => trim((string) ($this->plan['description'] ?? '')) ?: null,
            'contact_sales' => (bool) ($this->plan['contact_sales'] ?? false),
            'is_public' => (bool) ($this->plan['is_public'] ?? true),
            'is_highlighted' => (bool) ($this->plan['is_highlighted'] ?? false),
            'price_monthly_cents' => PlatformBilling::toCents($this->plan['price_monthly'] ?? null),
            'price_yearly_cents' => PlatformBilling::toCents($this->plan['price_yearly'] ?? null),
            'stripe_price_monthly' => trim((string) ($this->plan['stripe_price_monthly'] ?? '')) ?: null,
            'stripe_price_yearly' => trim((string) ($this->plan['stripe_price_yearly'] ?? '')) ?: null,
            'quotas' => $quotas,
            'features' => $features,
            'extras' => $extras,
        ];
        if ($isNew) {
            $plan = PlatformPlan::create(array_merge($attributes, [
                'slug' => $this->plan['slug'],
                'position' => (int) PlatformPlan::query()->max('position') + 1,
            ]));
            audit_log('platform.plan.created', $plan, ['slug' => $plan->slug, 'name' => $plan->name], 0);
        } else {
            $plan = PlatformPlan::query()->where('slug', $this->editing)->firstOrFail();
            $before = $plan->only(array_keys($attributes));
            $plan->update($attributes);
            audit_log('platform.plan.updated', $plan, ['slug' => $plan->slug, 'changed' => array_keys(array_filter($attributes, fn ($value, $key) => $before[$key] != $value, ARRAY_FILTER_USE_BOTH))], 0);
        }
        PlanCatalog::load();
        $this->cancel();
        $this->dispatch('success', __('Plan saved. Customers on it get the new limits within a few minutes.'));
    }

    public function move(string $slug, int $direction): void
    {
        if (! $this->authorizeEdit()) {
            return;
        }
        $plans = PlatformPlan::query()->orderBy('position')->orderBy('id')->get()->values();
        $index = $plans->search(fn (PlatformPlan $plan) => $plan->slug === $slug);
        $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);
        if ($index === false || $target < 0 || $target >= $plans->count()) {
            return;
        }
        $ordered = $plans->all();
        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
        foreach ($ordered as $position => $plan) {
            $plan->update(['position' => $position + 1]);
        }
        PlanCatalog::load();
    }

    public function confirmDelete(string $slug): void
    {
        if (! $this->authorizeEdit()) {
            return;
        }
        $this->deleting = $slug;
        $this->replacement = (string) collect(array_keys(PlanCatalog::all()))->first(fn (string $key) => $key !== $slug);
    }

    public function delete(): void
    {
        $this->resetErrorBag();
        if (! $this->authorizeEdit() || is_null($this->deleting)) {
            return;
        }
        $plan = PlatformPlan::query()->where('slug', $this->deleting)->firstOrFail();
        if (PlatformPlan::query()->count() <= 1) {
            $this->dispatch('error', __('The platform needs at least one plan.'));

            return;
        }
        $customers = PlanCatalog::tenantsOn($plan->slug);
        if ($customers > 0 && ($this->replacement === $plan->slug || ! PlatformPlan::query()->where('slug', $this->replacement)->exists())) {
            $this->addError('replacement', __('Choose the plan the customers of :plan move to.', ['plan' => $plan->name]));

            return;
        }
        DB::transaction(function () use ($plan) {
            $moved = PlatformTenant::query()->where('plan', $plan->slug)->update(['plan' => $this->replacement]);
            PlatformTenant::query()->where('pending_plan', $plan->slug)->update(['pending_plan' => null, 'pending_interval' => null]);
            PlatformInvoice::query()->where('plan', $plan->slug)->where('status', PlatformInvoice::STATUS_OPEN)->get()
                ->each(fn (PlatformInvoice $invoice) => PlatformBilling::cancelInvoice($invoice, 'user'));
            audit_log('platform.plan.deleted', $plan, ['slug' => $plan->slug, 'moved_to' => $this->replacement, 'customers' => $moved], 0);
            $plan->delete();
        });
        PlanCatalog::load();
        $this->reset('deleting', 'replacement');
        $this->dispatch('success', __('Plan removed.'));
    }

    public function render()
    {
        $plans = PlatformPlan::query()->orderBy('position')->orderBy('id')->get();
        $customers = PlatformTenant::query()->whereNotNull('plan')->selectRaw('plan, COUNT(*) as total')->groupBy('plan')->pluck('total', 'plan');

        return view('livewire.platform.admin-plans', [
            'plans' => $plans,
            'customers' => $customers,
            'quotaDefinitions' => PlanCatalog::QUOTAS,
            'featureDefinitions' => PlanCatalog::FEATURES,
            'canEdit' => $this->canEdit(),
            'stripe' => config('platform.billing.provider') === 'stripe',
        ]);
    }

    private function loadPlan(?PlatformPlan $plan): void
    {
        $money = fn (?int $cents) => $cents ? number_format($cents / 100, 2, ',', '') : '';
        $this->resetErrorBag();
        $this->plan = [
            'slug' => (string) $plan?->slug,
            'name' => (string) $plan?->name,
            'description' => (string) $plan?->description,
            'price_monthly' => $money($plan?->price_monthly_cents),
            'price_yearly' => $money($plan?->price_yearly_cents),
            'contact_sales' => (bool) $plan?->contact_sales,
            'is_public' => $plan ? $plan->is_public : true,
            'is_highlighted' => (bool) $plan?->is_highlighted,
            'stripe_price_monthly' => (string) $plan?->stripe_price_monthly,
            'stripe_price_yearly' => (string) $plan?->stripe_price_yearly,
        ];
        $quotas = array_merge(PlanCatalog::emptyQuotas(), (array) $plan?->quotas);
        $this->quotas = [];
        $this->unlimited = [];
        foreach (PlanCatalog::QUOTAS as $key => $definition) {
            $value = (float) ($quotas[$key] ?? 0);
            $this->unlimited[$key] = $definition['unlimited'] && $value <= 0;
            $this->quotas[$key] = $value > 0 ? rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') : '';
        }
        $this->features = [];
        foreach (array_keys(PlanCatalog::FEATURES) as $key) {
            $this->features[$key] = (bool) data_get($plan?->features, $key, false);
        }
        $this->extras = array_values(array_map(fn ($extra) => [
            'text' => (string) data_get($extra, 'text', ''),
            'help' => (string) data_get($extra, 'help', ''),
        ], (array) $plan?->extras));
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
        abort_unless($this->canView(), 403);
        if (! $this->canEdit()) {
            $this->dispatch('error', __('Only the owner of the root team can change the plans.'));

            return false;
        }

        return true;
    }
}
