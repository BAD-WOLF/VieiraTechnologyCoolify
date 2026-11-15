<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * API tokens of the operator (root team) manage every node and tenant: with
 * PLATFORM_OPERATOR_API_CIDRS they are only accepted from those networks (CI runners, VPN,
 * Ansible host), so a leaked token is useless elsewhere. Tenant tokens are not affected.
 */
class PlatformOperatorApiAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $cidrs = (array) config('platform.security.operator_api_cidrs', []);
        $bearer = $request->bearerToken();
        if (! isPlatform() || $cidrs === [] || blank($bearer) || ! str_contains($bearer, '|')) {
            return $next($request);
        }
        $token = Sanctum::$personalAccessTokenModel::findToken($bearer);
        if ($token && (int) data_get($token, 'team_id') === 0 && ! IpUtils::checkIp((string) $request->ip(), $cidrs)) {
            audit_log('api_token.blocked_source', null, ['ip' => $request->ip(), 'token' => $token->name], 0, 'anonymous');

            return response()->json(['message' => 'This token can not be used from this address.'], 403);
        }

        return $next($request);
    }
}
