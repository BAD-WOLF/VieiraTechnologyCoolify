<?php

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\PlatformInvoice;
use App\Models\PrivacyConsent;
use App\Models\PrivacyDeletedAccount;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * End of the processing (LGPD, arts. 15 and 16): every day, whatever passed its period is deleted.
 * The register of security incidents is kept (at least five years, Resolução CD/ANPD nº 15/2024).
 */
class PrivacyRetention
{
    /**
     * Years the proof of a consent, a request or a deleted account is kept after it ends.
     */
    public const PROOF_YEARS = 5;

    /**
     * @return array<string, int> what was deleted
     */
    public static function run(): array
    {
        $deleted = [];
        // Access records and the audit log (Marco Civil da Internet, art. 15: at least six months).
        $deleted['audit_logs'] = AuditLog::query()->where('created_at', '<', now()->subMonths(AccountDeletion::accessRecordMonths()))->delete();
        // Invoices of closed accounts, after the fiscal period.
        $deleted['invoices'] = PlatformInvoice::query()->whereNull('team_id')->whereNotNull('retain_until')->where('retain_until', '<', now())->delete();
        // Record of a deleted account (with its e-mail), once its access records are gone.
        $deleted['deleted_accounts'] = PrivacyDeletedAccount::query()->where('status', PrivacyDeletedAccount::STATUS_DONE)->where('retain_until', '<', now())->delete();
        // Proof of consents and answered requests of accounts that no longer exist.
        $cutoff = now()->subYears(self::PROOF_YEARS);
        $deleted['consents'] = PrivacyConsent::query()->where('created_at', '<', $cutoff)->whereNotIn('user_id', User::query()->select('id'))->delete();
        $deleted['requests'] = PrivacyRequest::query()->where('status', PrivacyRequest::STATUS_ANSWERED)->where('answered_at', '<', $cutoff)->delete();
        $deleted['unverified_accounts'] = self::deleteUnverified();
        if (array_sum($deleted) > 0) {
            audit_log('privacy.retention', null, $deleted, 0, 'system');
        }

        return $deleted;
    }

    /**
     * Sign-ups that never confirmed the e-mail: nothing was contracted, so nothing is kept
     * (principle of necessity, art. 6º, III).
     */
    private static function deleteUnverified(): int
    {
        $days = max(7, (int) config('platform.privacy.unverified_days', 30));
        $count = 0;
        User::query()
            ->whereNull('email_verified_at')
            ->where('id', '!=', 0)
            ->where('created_at', '<', now()->subDays($days))
            ->whereDoesntHave('teams', fn (Builder $query) => $query->where('teams.id', 0))
            ->whereNotIn('id', PrivacyDeletedAccount::query()->select('user_id'))
            ->limit(200)
            ->get()
            ->each(function (User $user) use (&$count) {
                try {
                    AccountDeletion::request($user, PrivacyDeletedAccount::BY_RETENTION, null, __('E-mail never confirmed'));
                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }
}
