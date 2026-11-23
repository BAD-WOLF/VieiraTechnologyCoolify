<?php

namespace App\Livewire\Platform;

use App\Models\PrivacyPreference;
use App\Services\Platform\Privacy;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Asks a user who has not yet seen the current privacy policy and terms of use to read and accept
 * them, with what changed (LGPD, art. 8º, § 6º; art. 9º, § 2º). Users who never chose about
 * promotions choose here, and users who never declared to be 18 or older declare it (art. 14).
 */
class PrivacyNotice extends Component
{
    public bool $marketing = false;

    public bool $adult = false;

    public function accept(): void
    {
        $user = auth()->user();
        if (is_null($user) || ! isPlatform()) {
            return;
        }
        $askAge = Privacy::needsAdultConfirmation($user);
        if ($askAge) {
            $this->validate(['adult' => ['accepted']], ['adult.accepted' => __('The account is only for people 18 or older.')]);
        }
        $askMarketing = ! PrivacyPreference::query()->whereKey($user->id)->exists();
        Privacy::acceptPolicy($user, $askMarketing ? $this->marketing : null, 'notice', $askAge && $this->adult);
        $this->dispatch('success', __('Thank you. You can review your choices in Privacy and data.'));
    }

    public function render(): View
    {
        $user = auth()->user();
        $askAge = isPlatform() && $user && Privacy::needsAdultConfirmation($user);
        $show = $askAge || (isPlatform() && $user && Privacy::needsPolicyAcceptance($user));

        return view('livewire.platform.privacy-notice', [
            'show' => $show,
            'askAge' => $askAge,
            'askMarketing' => $show && ! PrivacyPreference::query()->whereKey($user->id)->exists(),
            'version' => Privacy::policyVersion(),
            'changes' => Privacy::policyChanges(),
        ]);
    }
}
