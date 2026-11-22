<?php

namespace App\Http\Controllers;

use App\Services\Platform\Impersonation;
use Illuminate\Http\RedirectResponse;

/**
 * Ends "access as the customer" and brings the operator back to the console.
 */
class PlatformImpersonationController extends Controller
{
    public function stop(): RedirectResponse
    {
        Impersonation::stop();

        return redirect()->route('admin.platform', ['tab' => 'tenants']);
    }
}
