<?php

namespace App\Http\Controllers;

use App\Models\PlatformRegion;
use App\Services\Platform\EdgeRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Traefik HTTP provider endpoint of the regional edge routers (wiki 2, "Load Balancer regional e DNS").
 * Protected by PLATFORM_EDGE_TOKEN (Authorization: Bearer ...).
 */
class PlatformEdgeController extends Controller
{
    public function __invoke(Request $request, string $region): JsonResponse|Response
    {
        $token = (string) config('platform.edge.token');
        if (! isPlatform() || $token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response('Not Found', 404, ['Content-Type' => 'text/plain']);
        }
        $platformRegion = PlatformRegion::query()->where('slug', $region)->first();
        if (! $platformRegion) {
            return response('Not Found', 404, ['Content-Type' => 'text/plain']);
        }

        $config = Cache::remember(
            "platform:edge:{$platformRegion->id}",
            max(1, (int) config('platform.edge.cache_seconds', 10)),
            fn () => EdgeRouter::configFor($platformRegion),
        );

        return response()->json(EdgeRouter::toJson($config));
    }
}
