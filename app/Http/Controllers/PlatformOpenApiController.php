<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * OpenAPI document of the platform endpoints (resources/platform/openapi.yaml).
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
        return resource_path('platform/openapi.yaml');
    }
}
