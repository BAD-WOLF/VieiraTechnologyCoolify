<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * OpenAPI document of the platform endpoints (routes/platform/openapi.yaml, next to the routes it
 * describes; resources/ is not copied whole into the production image).
 */
class PlatformOpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $document = Cache::remember('platform-openapi:'.filemtime(self::path()), 3600, fn () => Yaml::parseFile(self::path()));

        return response()->json($document);
    }

    public static function path(): string
    {
        return base_path('routes/platform/openapi.yaml');
    }
}
