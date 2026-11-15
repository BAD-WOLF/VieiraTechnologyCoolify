<?php

namespace App\Http\Controllers\Api;

use App\Actions\Platform\ProvisionTenant;
use App\Http\Controllers\Controller;
use App\Models\AggregatedUsageHourly;
use App\Models\AuditLog;
use App\Models\PlatformInvoice;
use App\Models\PlatformRegion;
use App\Models\PlatformTenant;
use App\Models\Server;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PricingEngine;
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
        $plans = collect(PlanCatalog::all())->map(fn (array $plan, string $key) => [
            'id' => $key,
            'name' => $plan['name'],
            'description' => $plan['description'] ?? null,
            'quotas' => $plan['quotas'],
            'features' => $plan['features'],
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
        ]);
        $server = Server::query()->where('team_id', 0)->where('uuid', $uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        $region = PlatformRegion::query()->firstOrCreate(
            ['slug' => str($validated['region'])->lower()->slug()->value()],
            ['name' => $validated['region_name'] ?? $validated['region']],
        );
        $server->forceFill([
            'platform_region_id' => $region->id,
            'platform_dedicated_team_id' => $validated['dedicated_team_id'] ?? null,
        ])->save();
        audit_log('platform.node.assigned', $server, ['region' => $region->slug], 0);
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
