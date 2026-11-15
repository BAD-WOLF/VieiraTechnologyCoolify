<?php

namespace App\Http\Controllers;

use App\Services\Platform\TenantTelemetry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * forwardAuth of the regional OTLP gateway: 200 with the tenant (X-Scope-OrgID) for a valid
 * tenant token, 401 otherwise. The gateway overwrites any X-Scope-OrgID sent by the client.
 */
class PlatformOtlpAuthController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = TenantTelemetry::tokenFrom($request);
        $tenant = isPlatform() && $token ? TenantTelemetry::tenantForToken($token) : null;
        if (! $tenant) {
            return response('Unauthorized', 401, ['Content-Type' => 'text/plain', 'WWW-Authenticate' => 'Bearer']);
        }

        return response('', 200, ['X-Scope-OrgID' => TenantTelemetry::orgId($tenant), 'Cache-Control' => 'no-store']);
    }
}
