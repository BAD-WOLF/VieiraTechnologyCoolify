<?php

namespace App\Services\Platform;

use App\Models\PlatformTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * OpenTelemetry for tenant applications (wiki 6 and 8, tracing). Each tenant gets a token; the
 * regional OTLP gateway asks /api/v1/platform/otlp/auth which tenant the token belongs to and
 * stores the traces under that tenant only (Tempo multi-tenancy, X-Scope-OrgID).
 */
class TenantTelemetry
{
    private const PREFIX = 'hrt_';

    public static function issueToken(PlatformTenant $tenant): string
    {
        $token = self::PREFIX.Str::random(48);
        $previous = $tenant->otlp_token_hash;
        $tenant->forceFill(['otlp_token_hash' => hash('sha256', $token), 'otlp_token_created_at' => now()])->save();
        if ($previous) {
            Cache::forget('platform:otlp:'.$previous);
        }
        audit_log('platform.otlp.token_issued', $tenant, [], $tenant->team_id);

        return $token;
    }

    public static function revoke(PlatformTenant $tenant): void
    {
        if ($tenant->otlp_token_hash) {
            Cache::forget('platform:otlp:'.$tenant->otlp_token_hash);
        }
        $tenant->forceFill(['otlp_token_hash' => null, 'otlp_token_created_at' => null])->save();
        audit_log('platform.otlp.token_revoked', $tenant, [], $tenant->team_id);
    }

    public static function tokenFrom(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $match)) {
            return $match[1];
        }
        if (preg_match('/^Basic\s+(\S+)$/i', $header, $match)) {
            $decoded = base64_decode($match[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                return explode(':', $decoded, 2)[1];
            }
        }

        return null;
    }

    public static function tenantForToken(string $token): ?PlatformTenant
    {
        if (! str_starts_with($token, self::PREFIX) || strlen($token) > 100) {
            return null;
        }
        $hash = hash('sha256', $token);
        $tenantId = Cache::remember('platform:otlp:'.$hash, 60, fn () => (int) PlatformTenant::query()->where('otlp_token_hash', $hash)->value('id'));
        if ($tenantId <= 0) {
            return null;
        }
        $tenant = PlatformTenant::query()->find($tenantId);
        if (! $tenant || $tenant->otlp_token_hash !== $hash || ! $tenant->hasFeature('traces')
            || in_array($tenant->status, [PlatformTenant::STATUS_PENDING, PlatformTenant::STATUS_CANCELLED, PlatformTenant::STATUS_HARD_SUSPENDED], true)) {
            return null;
        }

        return $tenant;
    }

    public static function orgId(PlatformTenant $tenant): string
    {
        return 'tenant-'.$tenant->team_id;
    }

    public static function endpointFor(PlatformTenant $tenant): ?string
    {
        $pattern = (string) config('platform.telemetry.endpoint');
        $region = $tenant->region?->slug ?? config('platform.default_region');
        if ($pattern === '' || blank($region)) {
            return null;
        }

        return rtrim(str_replace('{region}', $region, $pattern), '/');
    }
}
