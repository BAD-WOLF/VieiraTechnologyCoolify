<?php

namespace App\Http\Controllers;

use App\Services\Platform\PlatformBranding;
use Illuminate\Http\Response;

/**
 * Logo and favicon of the dashboard when the operator did not configure one: a mark drawn from
 * the platform name, so no Coolify logo is shown.
 */
class PlatformBrandLogoController extends Controller
{
    public function __invoke(): Response
    {
        return response(PlatformBranding::logoSvg(), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
