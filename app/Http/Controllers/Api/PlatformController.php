<?php

namespace App\Http\Controllers\Api;

use App\Actions\Platform\ProvisionTenant;
use App\Actions\Platform\TrustEdgeProxies;
use App\Http\Controllers\Controller;
use App\Models\AggregatedUsageHourly;
use App\Models\AuditLog;
use App\Models\PlatformInvoice;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Server;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PricingEngine;
use App\Services\Platform\TenantLogs;
use App\Services\Platform\TenantUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-facing platform API (wiki 8: "Expor APIs para customers consultarem usage_events e invoices")
 * and operator endpoints used by the node bootstrap automation.
 */
class PlatformController extends Controller
{
    public function plans(): JsonResponse
    {
        $plans = collect(PlanCatalog::public())->map(fn (array $plan, string $key) => [
            'id' => $key,
            'name' => $plan['name'],
            'description' => $plan['description'] ?? null,
            'quotas' => $plan['quotas'],
            'features' => $plan['features'],
            'extras' => array_column(PlanCatalog::extraLines($plan), 'text'),
            'price_monthly_cents' => PlanCatalog::priceCents($key, PlanCatalog::INTERVAL_MONTHLY),
            'price_yearly_cents' => PlanCatalog::priceCents($key, PlanCatalog::INTERVAL_YEARLY),
            'self_service' => PlanCatalog::isSelfServe($key),
        ])->values();

        return response()->json($plans);
    }

    public function usage(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }
        [$from, $to] = TenantUsage::currentPeriod($tenant);
        $hourly = AggregatedUsageHourly::query()
            ->where('team_id', $tenant->team_id)
            ->where('period_start', '>=', $request->date('from') ?? $from)
            ->where('period_start', '<', $request->date('to') ?? now())
            ->orderBy('period_start')
            ->limit(24 * 93)
            ->get(['metric', 'period_start', 'period_end', 'value']);

        return response()->json([
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'period' => ['start' => $from->toIso8601String(), 'end' => $to->toIso8601String()],
            'quotas' => $tenant->quotas(),
            'current' => TenantUsage::snapshot($tenant),
            'invoice_preview' => PricingEngine::calculate($tenant, $from, now()),
            'hourly' => $hourly,
        ]);
    }

    public function invoices(): JsonResponse
    {
        $tenant = $this->tenant();
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        return response()->json(
            PlatformInvoice::query()->where('team_id', $tenant->team_id)->latest('period_start')->limit(100)->get([
                'uuid', 'type', 'period_start', 'period_end', 'amount_cents', 'currency', 'status', 'lines', 'stripe_hosted_invoice_url', 'paid_at',
            ])
        );
    }

    public function auditLogs(): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        return response()->json(
            AuditLog::query()->where('team_id', $teamId)->latest('id')->limit(200)->get([
                'id', 'user_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'ip_address', 'metadata', 'created_at',
            ])
        );
    }

    /**
     * Tenant: log history of one of its resources (regional Loki), oldest line first.
     */
    public function logs(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }
        $validated = $request->validate([
            'resource_uuid' => ['required', 'string', 'max:64'],
            'range' => ['nullable', 'in:'.implode(',', array_keys(TenantLogs::RANGES))],
            'filter' => ['nullable', 'string', 'max:'.TenantLogs::MAX_FILTER_LENGTH],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('platform.logs.max_lines', 1000)],
        ]);
        $resource = TenantLogs::findResource($tenant->team_id, $validated['resource_uuid']);
        if (is_null($resource)) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }
        $result = TenantLogs::fetch($tenant->team_id, $resource, $validated['range'] ?? '1h', (string) ($validated['filter'] ?? ''), (int) ($validated['limit'] ?? 500));
        if ($result['error']) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json(['resource_uuid' => $validated['resource_uuid'], 'lines' => $result['lines']]);
    }

    /**
     * Operator only: assign a node to a region (used by infra/scripts/register-node.sh).
     */
    public function assignServerRegion(Request $request, string $uuid): JsonResponse
    {
        if ((int) getTeamIdFromToken() !== 0) {
            return response()->json(['message' => 'Only the platform operator can manage nodes.'], 403);
        }
        $validated = $request->validate([
            'region' => ['required', 'string', 'max:64'],
            'region_name' => ['nullable', 'string', 'max:255'],
            'dedicated_team_id' => ['nullable', 'integer'],
            'wildcard_domain' => ['nullable', 'url', 'max:255'],
            'logs_url' => ['nullable', 'url:http,https', 'max:255'],
            // Networks of the edge routers of the region (the node proxies trust their headers).
            'trusted_proxies' => ['nullable', 'array', 'max:10'],
            'trusted_proxies.*' => ['string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
                if (! TrustEdgeProxies::isCidr((string) $value)) {
                    $fail("{$attribute} must be a network in CIDR notation (e.g. 10.20.0.0/16).");
                }
            }],
        ]);
        $server = Server::query()->where('team_id', 0)->where('uuid', $uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        $region = PlatformRegion::query()->firstOrCreate(
            ['slug' => str($validated['region'])->lower()->slug()->value()],
            ['name' => $validated['region_name'] ?? $validated['region']],
        );
        if (! empty($validated['logs_url'])) {
            $region->forceFill(['logs_url' => rtrim($validated['logs_url'], '/')])->save();
        }
        $server->forceFill([
            'platform_region_id' => $region->id,
            'platform_dedicated_team_id' => $validated['dedicated_team_id'] ?? null,
        ])->save();
        if (! empty($validated['wildcard_domain'])) {
            $server->settings->forceFill(['wildcard_domain' => rtrim($validated['wildcard_domain'], '/')])->save();
        }
        audit_log('platform.node.assigned', $server, ['region' => $region->slug], 0);
        if (! empty($validated['trusted_proxies'])) {
            TrustEdgeProxies::dispatch($server, array_values($validated['trusted_proxies']));
        }
        PlatformTenant::query()->where('platform_region_id', $region->id)->whereNotNull('provisioned_at')->pluck('team_id')
            ->each(fn ($teamId) => ProvisionTenant::dispatch($teamId, $region));

        return response()->json(['message' => 'Node assigned.', 'region' => $region->slug]);
    }

    /**
     * Operator only: (re)provision the networks of a tenant.
     */
    public function provisionTenant(int $teamId): JsonResponse
    {
        if ((int) getTeamIdFromToken() !== 0) {
            return response()->json(['message' => 'Only the platform operator can provision tenants.'], 403);
        }
        if ($teamId === 0) {
            return response()->json(['message' => 'The operator team is not a tenant.'], 422);
        }
        ProvisionTenant::dispatch($teamId);

        return response()->json(['message' => 'Provisioning queued.']);
    }

    private function tenant(): PlatformTenant|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! isPlatformTenant((int) $teamId)) {
            return response()->json(['message' => 'Only available for platform tenants.'], 404);
        }

        return PlatformTenant::forTeam((int) $teamId);
    }
}
