<?php

namespace App\Actions\Platform;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Services\Platform\Privacy;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Sign-up of the platform: the Coolify one, after the person accepts the privacy policy and the
 * terms of use and declares to be 18 or older (LGPD, arts. 8º, 9º and 14), choosing freely whether
 * to receive promotions and news. Each choice is recorded as proof (art. 8º, § 2º).
 */
class CreatePlatformUser implements CreatesNewUsers
{
    public function __construct(private CreateNewUser $users) {}

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! isPlatform()) {
            return $this->users->create($input);
        }
        // The first account is the owner of the installation, the controller itself.
        $first = ! User::query()->exists();
        if (! $first) {
            Validator::make($input, [
                'accept_policy' => ['accepted'],
                'adult' => ['accepted'],
            ], [
                'accept_policy.accepted' => __('Accept the privacy policy and the terms of use to create the account.'),
                'adult.accepted' => __('The account is only for people 18 or older.'),
            ])->validate();
        }
        $user = $this->users->create($input);
        if (! $first) {
            Privacy::onRegistered($user, filter_var($input['marketing'] ?? false, FILTER_VALIDATE_BOOLEAN));
        }

        return $user;
    }
}
