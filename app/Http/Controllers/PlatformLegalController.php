<?php

namespace App\Http\Controllers;

use App\Services\Platform\AccountDeletion;
use App\Services\Platform\Privacy;
use App\Services\Platform\PrivacyRetention;
use App\Services\Platform\ProcessingRecord;
use Illuminate\Contracts\View\View;

/**
 * Public privacy policy and terms of use (LGPD, art. 9º: clear, adequate and ostensive), built
 * from what the platform does and how the owner configured it.
 */
class PlatformLegalController extends Controller
{
    public function privacy(): View
    {
        abort_unless(isPlatform(), 404);

        return view('platform.legal.privacy', $this->common() + [
            'operations' => ProcessingRecord::operations(),
            'processors' => Privacy::processors(),
            'proofYears' => PrivacyRetention::PROOF_YEARS,
            'unverifiedDays' => max(7, (int) config('platform.privacy.unverified_days', 30)),
        ]);
    }

    public function terms(): View
    {
        abort_unless(isPlatform(), 404);

        return view('platform.legal.terms', $this->common());
    }

    /**
     * The CNPJ or CPF of the controller, with its name: "CNPJ 12.345.678/0001-90".
     */
    private static function document(): ?string
    {
        $document = trim((string) config('platform.privacy.controller_document'));
        if ($document === '') {
            return null;
        }

        return (strlen((string) preg_replace('/\D/', '', $document)) === 14 ? 'CNPJ ' : 'CPF ').$document;
    }

    /**
     * @return array<string, mixed>
     */
    private function common(): array
    {
        return [
            'platform' => (string) config('platform.name'),
            'controller' => Privacy::controllerName(),
            'document' => self::document(),
            'address' => config('platform.privacy.controller_address'),
            'contact' => Privacy::contactEmail(),
            'officer' => Privacy::officer(),
            'version' => Privacy::policyVersion(),
            'publishedAt' => Privacy::policyPublishedAt(),
            'changes' => Privacy::policyChanges(),
            'hosting' => (string) config('platform.privacy.hosting'),
            'auditMonths' => AccountDeletion::accessRecordMonths(),
            'fiscalYears' => AccountDeletion::fiscalYears(),
            'suspendDays' => (int) config('platform.enforcement.suspend_after_days', 7),
            'stopDays' => (int) config('platform.enforcement.hard_suspend_after_days', 30),
            'policyExtra' => config('platform.privacy.policy_extra'),
            'termsExtra' => config('platform.privacy.terms_extra'),
            'externalPrivacy' => config('platform.branding.privacy_url'),
            'externalTerms' => config('platform.branding.terms_url'),
        ];
    }
}
