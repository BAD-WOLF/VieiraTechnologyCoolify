<?php

namespace App\Livewire\Platform;

use App\Services\Platform\TenantTelemetry;
use Livewire\Component;

/**
 * OpenTelemetry endpoint and token of the tenant (traces of its applications).
 */
class Observability extends Component
{
    public ?string $newToken = null;

    public function generate(): void
    {
        $tenant = platformTenant();
        if (! $tenant || ! auth()->user()?->isAdmin() || ! $tenant->hasFeature('traces')) {
            $this->dispatch('error', __('Your plan does not include application traces.'));

            return;
        }
        $this->newToken = TenantTelemetry::issueToken($tenant);
    }

    public function revoke(): void
    {
        $tenant = platformTenant();
        if ($tenant && auth()->user()?->isAdmin()) {
            TenantTelemetry::revoke($tenant);
            $this->newToken = null;
            $this->dispatch('success', __('Token revoked.'));
        }
    }

    public function render()
    {
        $tenant = platformTenant();

        return view('livewire.platform.observability', [
            'enabled' => (bool) $tenant?->hasFeature('traces'),
            'endpoint' => $tenant ? TenantTelemetry::endpointFor($tenant) : null,
            'hasToken' => filled($tenant?->otlp_token_hash),
            'createdAt' => $tenant?->otlp_token_created_at,
            'isAdmin' => (bool) auth()->user()?->isAdmin(),
        ]);
    }
}
