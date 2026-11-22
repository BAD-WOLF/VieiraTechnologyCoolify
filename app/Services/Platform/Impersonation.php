<?php

namespace App\Services\Platform;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * "Access as the customer": an operator of the root team opens the dashboard as a user of a
 * customer, to see what they see and help them, and goes back to the operator console.
 *
 * Every start and end goes to the audit trail, and every action taken meanwhile is recorded with
 * the operator who took it (audit_log). Operators can not be impersonated, and actions that ask
 * for the password still ask for the customer's.
 */
class Impersonation
{
    public const SESSION_KEY = 'platform.impersonator';

    public static function active(): bool
    {
        return filled(session(self::SESSION_KEY));
    }

    public static function impersonatorId(): ?int
    {
        $id = session(self::SESSION_KEY);

        return filled($id) ? (int) $id : null;
    }

    public static function canImpersonate(?User $operator, User $target): bool
    {
        if (! isPlatform() || is_null($operator) || self::active() || $operator->id === $target->id) {
            return false;
        }
        $isOperator = fn (User $user) => $user->teams()->where('teams.id', 0)->exists();

        return $isOperator($operator) && $operator->isInstanceAdmin() && ! $isOperator($target);
    }

    public static function start(User $target, Team $team): void
    {
        $operator = auth()->user();
        abort_unless($operator instanceof User && self::canImpersonate($operator, $target), 403);
        abort_unless($target->teams()->where('teams.id', $team->id)->exists(), 404);

        audit_log('platform.impersonation.started', $target, ['operator_id' => $operator->id, 'user_id' => $target->id], $team->id);
        Auth::login($target);
        session()->regenerate();
        session([self::SESSION_KEY => $operator->id, 'currentTeam' => $team]);
        refreshSession($team);
    }

    public static function stop(): void
    {
        $operatorId = self::impersonatorId();
        if (is_null($operatorId)) {
            return;
        }
        $customer = auth()->user();
        $operator = User::query()->find($operatorId);
        session()->forget(self::SESSION_KEY);
        if (is_null($operator)) {
            Auth::logout();

            return;
        }
        audit_log('platform.impersonation.ended', $customer, ['operator_id' => $operator->id, 'user_id' => $customer?->id], currentTeam()?->id);
        Auth::login($operator);
        session()->regenerate();
        $root = Team::query()->find(0);
        session(['currentTeam' => $root]);
        refreshSession($root);
    }
}
