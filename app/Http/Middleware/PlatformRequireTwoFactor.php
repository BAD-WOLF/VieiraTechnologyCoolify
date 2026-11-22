<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is mandatory for the operator (root team: full access to every
 * tenant and node) and, with PLATFORM_REQUIRE_2FA=all, for every user (wiki 9, "Permissões de
 * Sistema"). Until it is enabled the user can only reach the profile page to enable it.
 */
class PlatformRequireTwoFactor
{
    /** @var list<string> */
    private const ALLOWED_PATHS = [
        'profile', 'logout', 'login', 'two-factor-challenge', 'verify', 'email/verify*', 'livewire/update', 'livewire/*',
        'user/two-factor-authentication', 'user/confirmed-two-factor-authentication', 'user/two-factor-qr-code',
        'user/two-factor-secret-key', 'user/two-factor-recovery-codes', 'user/confirm-password', 'user/confirmed-password-status',
        'force-password-reset',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! isPlatform() || filled($user->two_factor_confirmed_at) || ! self::requiredFor($user)) {
            return $next($request);
        }
        // An operator (with two factors) accessing the dashboard as a customer.
        if ($request->hasSession() && \App\Services\Platform\Impersonation::active()) {
            return $next($request);
        }
        if ($request->is(...self::ALLOWED_PATHS) || $request->expectsJson()) {
            return $next($request);
        }

        return redirect()->route('profile')->with('error', __('Enable two-factor authentication to continue.'));
    }

    public static function requiredFor(User $user): bool
    {
        return match ((string) config('platform.security.require_two_factor', 'operator')) {
            'all' => true,
            'operator' => $user->teams()->where('teams.id', 0)->exists(),
            default => false,
        };
    }
}
