<?php

namespace App\Services\Platform;

use App\Jobs\DeleteResourceJob;
use App\Jobs\PlatformAccountDeletionJob;
use App\Jobs\PlatformPrivacyNoticeJob;
use App\Models\PlatformInvoice;
use App\Models\PlatformNotification;
use App\Models\PlatformTenant;
use App\Models\PrivacyDeletedAccount;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Deletion of an account (LGPD, art. 18, VI; arts. 15 and 16), asked by the user or done by the
 * owner. Teams the user is alone in are closed: their applications, databases and services are
 * removed from the nodes and the team is deleted. Teams shared with other people stay with them.
 * What the law makes the platform keep is kept, and only that: the invoices with the payer data
 * (tax law, five years) and the access records (Marco Civil da Internet, art. 15, six months).
 */
class AccountDeletion
{
    /**
     * What deleting the account does, and what prevents it.
     *
     * @return array{blockers: list<string>, close: list<Team>, leave: list<Team>, transfer: list<Team>, workloads: int, invoices: int, open_invoices: int, payment_customers: int}
     */
    public static function preview(User $user, bool $byOwner = false): array
    {
        $preview = ['blockers' => [], 'close' => [], 'leave' => [], 'transfer' => [], 'workloads' => 0, 'invoices' => 0, 'open_invoices' => 0, 'payment_customers' => 0];
        $overdue = false;
        if ($user->id === 0) {
            $preview['blockers'][] = __('The first account of the installation can not be deleted.');
        }
        if (PrivacyDeletedAccount::query()->where('user_id', $user->id)->where('status', PrivacyDeletedAccount::STATUS_PENDING)->exists()) {
            $preview['blockers'][] = __('This account is already being deleted.');
        }
        foreach ($user->teams()->with('members')->get() as $team) {
            $role = $team->pivot->role;
            $others = $team->members->where('id', '!=', $user->id);
            if ($team->id === 0) {
                if ($role === 'owner' && $others->where('pivot.role', 'owner')->isEmpty()) {
                    $preview['blockers'][] = __('This is the only owner of the platform: make another operator the owner first.');
                }
                $preview['leave'][] = $team;

                continue;
            }
            if ($others->isEmpty()) {
                $preview['close'][] = $team;
                $preview['workloads'] += count(TenantLifecycle::workloads($team->id));
                $preview['invoices'] += PlatformInvoice::query()->where('team_id', $team->id)->where('status', '!=', PlatformInvoice::STATUS_DRAFT)->count();
                $preview['open_invoices'] += PlatformInvoice::query()->where('team_id', $team->id)->where('status', PlatformInvoice::STATUS_OPEN)->count();
                // Stripe keeps the invoices of the subscription: the platform knows only that it is late.
                $overdue = $overdue || PlatformTenant::query()->where('team_id', $team->id)->whereNotNull('past_due_since')->exists();
                $preview['payment_customers'] += Subscription::query()->where('team_id', $team->id)->whereNotNull('stripe_customer_id')->count();

                continue;
            }
            $hasOtherOwner = $others->contains(fn ($member) => in_array($member->pivot->role, ['owner', 'admin'], true));
            if ($role === 'owner' && ! $hasOtherOwner) {
                $preview['transfer'][] = $team;
            } else {
                $preview['leave'][] = $team;
            }
        }
        if (! $byOwner && $preview['open_invoices'] > 0) {
            $preview['blockers'][] = trans_choice('{1} There is :count open invoice. Pay it (or talk to us) before deleting the account.|[2,*] There are :count open invoices. Pay them (or talk to us) before deleting the account.', $preview['open_invoices'], ['count' => $preview['open_invoices']]);
        } elseif (! $byOwner && $overdue) {
            $preview['blockers'][] = __('There is an overdue payment. Pay it (or talk to us) before deleting the account.');
        }

        return $preview;
    }

    /**
     * Starts the deletion: the user is logged out everywhere at once, can no longer sign in and
     * receives by e-mail what was deleted and what is kept, and for how long. The removal from the
     * nodes runs in the background.
     */
    public static function request(User $user, string $by, ?User $actor = null, ?string $reason = null): PrivacyDeletedAccount
    {
        $preview = self::preview($user, $by !== PrivacyDeletedAccount::BY_SELF);
        if ($preview['blockers'] !== []) {
            throw new \RuntimeException(implode(' ', $preview['blockers']));
        }
        $record = PrivacyDeletedAccount::query()->create([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'requested_by' => $by,
            'actor_id' => $actor?->id,
            'reason' => filled($reason) ? str($reason)->limit(497)->value() : null,
            'status' => PrivacyDeletedAccount::STATUS_PENDING,
            'retain_until' => now()->addMonths(self::accessRecordMonths()),
        ]);
        self::lockOut($user);
        audit_log('privacy.account.deletion_requested', null, ['user_id' => $user->id, 'by' => $by, 'reason' => $record->reason], 0,
            match ($by) {
                PrivacyDeletedAccount::BY_SELF => 'user',
                PrivacyDeletedAccount::BY_OWNER => 'operator',
                default => 'system',
            });
        // An address never confirmed may not belong to whom signed up: no e-mail to it.
        if ($by !== PrivacyDeletedAccount::BY_RETENTION) {
            PlatformPrivacyNoticeJob::dispatch([$user->email], __('Your account was deleted'), self::noticeLines($preview, $by, $record->reason));
        }
        PlatformAccountDeletionJob::dispatch($record->id);

        return $record;
    }

    /**
     * Runs the deletion (in the background). Stops without deleting the user when an application
     * can not be removed from its node, so nothing of the customer keeps running: the owner sees it
     * failed and tries again.
     */
    public static function execute(PrivacyDeletedAccount $record): void
    {
        $user = User::query()->find($record->user_id);
        if (is_null($user)) {
            $record->forceFill(['status' => PrivacyDeletedAccount::STATUS_DONE, 'completed_at' => $record->completed_at ?? now()])->save();

            return;
        }
        $preview = self::preview($user, true);
        $blockers = array_values(array_filter($preview['blockers'], fn (string $blocker) => $blocker !== __('This account is already being deleted.')));
        if ($blockers !== []) {
            $record->forceFill(['status' => PrivacyDeletedAccount::STATUS_FAILED, 'error' => implode(' ', $blockers)])->save();

            return;
        }
        $summary = ['teams_closed' => 0, 'teams_left' => count($preview['leave']), 'teams_transferred' => count($preview['transfer']), 'workloads' => 0, 'invoices_kept' => 0, 'invoices_cancelled' => 0, 'payment_customers_deleted' => 0, 'payer_contacts_replaced' => 0];
        $handedOver = [];
        try {
            // The payment provider first: when Stripe can not be reached nothing is deleted yet and
            // the owner tries again, instead of a deleted account whose card is still charged.
            foreach ($preview['close'] as $team) {
                if (StripeBilling::deleteCustomer($team)) {
                    $summary['payment_customers_deleted']++;
                }
            }
            foreach ([...$preview['leave'], ...$preview['transfer']] as $team) {
                $contact = $team->id === 0 ? null : self::handOver($team, $user);
                if ($contact) {
                    $handedOver[] = [$team, $contact];
                    $summary['payer_contacts_replaced']++;
                }
            }
            foreach ($preview['close'] as $team) {
                self::closeTeam($team, $summary);
            }
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            TeamInvitation::query()->where('email', $user->email)->delete();
            self::lockOut($user);
            Cache::forget('team:'.$user->id);
            // Coolify leaves the shared teams (another member becomes owner when needed) and deletes
            // the closed ones, with their projects, keys, sources and variables.
            $user->delete();
        } catch (\Throwable $e) {
            $record->forceFill(['status' => PrivacyDeletedAccount::STATUS_FAILED, 'error' => str($e->getMessage())->limit(2000)->value(), 'summary' => $summary])->save();
            report($e);

            return;
        }
        $record->forceFill(['status' => PrivacyDeletedAccount::STATUS_DONE, 'completed_at' => now(), 'error' => null, 'summary' => $summary])->save();
        audit_log('privacy.account.deleted', null, ['user_id' => $record->user_id, 'summary' => $summary], 0, 'system');
        // Told once the person is out of the team, so the notice does not reach them.
        foreach ($handedOver as [$team, $contact]) {
            $tenant = PlatformTenant::query()->where('team_id', $team->id)->first();
            if ($tenant) {
                TenantLifecycle::notify($tenant, __('Check the payer data'), [
                    __(':name is no longer in the team :team.', ['name' => $record->name, 'team' => $team->name]),
                    __('The e-mail of whom pays was theirs and is now :email. Check the other payer data too: name, CPF or CNPJ and phone.', ['email' => $contact]),
                ], PlatformNotification::KIND_BILLING);
            }
        }
    }

    /**
     * Tries a failed deletion again (owner).
     */
    public static function retry(PrivacyDeletedAccount $record): void
    {
        if ($record->status !== PrivacyDeletedAccount::STATUS_FAILED) {
            return;
        }
        $record->forceFill(['status' => PrivacyDeletedAccount::STATUS_PENDING, 'error' => null])->save();
        PlatformAccountDeletionJob::dispatch($record->id);
    }

    public static function accessRecordMonths(): int
    {
        return max(6, (int) config('platform.privacy.audit_months', 12));
    }

    public static function fiscalYears(): int
    {
        return max(5, (int) config('platform.privacy.fiscal_years', 5));
    }

    /**
     * @param  array<string, int>  $summary
     */
    private static function closeTeam(Team $team, array &$summary): void
    {
        $tenant = PlatformTenant::query()->where('team_id', $team->id)->first();
        $payer = self::payer($tenant, $team);
        foreach (PlatformInvoice::query()->where('team_id', $team->id)->get() as $invoice) {
            if ($invoice->status === PlatformInvoice::STATUS_DRAFT) {
                $invoice->delete();

                continue;
            }
            if ($invoice->status === PlatformInvoice::STATUS_OPEN) {
                PlatformBilling::cancelInvoice($invoice, 'system');
                $invoice->refresh();
                $summary['invoices_cancelled']++;
            }
            // Tax law: the invoice and whom it charged stay for the fiscal period (art. 16, I).
            $invoice->forceFill(['payer' => $payer, 'retain_until' => now()->addYears(self::fiscalYears())])->save();
            $summary['invoices_kept']++;
        }
        foreach (TenantLifecycle::workloads($team->id) as $resource) {
            DeleteResourceJob::dispatchSync($resource);
            $summary['workloads']++;
        }
        // Coolify keeps the subscription of a deleted team; its customer is already gone at Stripe.
        Subscription::query()->where('team_id', $team->id)->delete();
        $summary['teams_closed']++;
    }

    /**
     * A team that stays with other people may still have the leaving user as the contact of whom
     * pays (the e-mail of the payer data, the customer at Stripe): the next person in charge (an
     * owner, then an admin, then a member, as Coolify chooses the new owner) takes it, so invoices
     * and receipts stop going to the person (art. 18, § 6º). The e-mail it got, when replaced.
     */
    private static function handOver(Team $team, User $user): ?string
    {
        $contact = $team->members()->where('users.id', '!=', $user->id)
            ->orderByRaw("case team_user.role when 'owner' then 0 when 'admin' then 1 else 2 end")->orderBy('users.id')->first();
        if (is_null($contact)) {
            return null;
        }
        $replaced = false;
        $tenant = PlatformTenant::query()->where('team_id', $team->id)->first();
        $profile = (array) ($tenant?->billing_profile ?? []);
        if ($tenant && strcasecmp(trim((string) ($profile['email'] ?? '')), $user->email) === 0) {
            $tenant->forceFill(['billing_profile' => array_merge($profile, ['email' => $contact->email])])->save();
            $replaced = true;
        }
        if (StripeBilling::replaceCustomerEmail($team, $user->email, $contact->email)) {
            $replaced = true;
        }

        return $replaced ? $contact->email : null;
    }

    /**
     * @return array<string, string>
     */
    private static function payer(?PlatformTenant $tenant, Team $team): array
    {
        $profile = (array) ($tenant?->billing_profile ?? []);

        return array_filter([
            'account' => $team->name,
            'name' => (string) ($profile['name'] ?? ''),
            'document' => (string) ($profile['document'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'phone' => (string) ($profile['phone'] ?? ''),
        ], 'filled');
    }

    /**
     * Logged out everywhere and unable to sign in again (password and API tokens).
     */
    private static function lockOut(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        // Quietly: Coolify ends the current session on a password change, which would log out the
        // owner deleting someone else's account.
        $user->forceFill(['password' => Hash::make(Str::random(64)), 'remember_token' => null])->saveQuietly();
    }

    /**
     * @param  array{close: list<Team>, leave: list<Team>, transfer: list<Team>, workloads: int, invoices: int}  $preview
     * @return list<string>
     */
    private static function noticeLines(array $preview, string $by, ?string $reason): array
    {
        $contact = Privacy::contactEmail();

        return array_values(array_filter([
            $by === PrivacyDeletedAccount::BY_OWNER
                ? __('The platform deleted your account.').(filled($reason) ? ' '.__('Reason: :reason', ['reason' => $reason]) : '')
                : __('We deleted your account, as you asked.'),
            __('Deleted: your profile, the teams only you were in, with their applications, databases, services, volumes and backups, your API tokens and your choices.'),
            $preview['payment_customers'] > 0 ? __('At Stripe, your subscription is cancelled and your customer record is deleted, so your card is not charged again.') : null,
            count($preview['leave']) + count($preview['transfer']) > 0 ? __('The teams you shared with other people stay with them.') : null,
            $preview['invoices'] > 0 ? __('Kept for :years years, as tax law requires: your invoices, with the name and the CPF or CNPJ of the payer.', ['years' => self::fiscalYears()]) : null,
            __('Kept for :months months, as the Marco Civil da Internet requires: the records of your accesses (date, time and IP address).', ['months' => self::accessRecordMonths()]),
            $contact ? __('Questions: :email.', ['email' => $contact]) : null,
        ]));
    }
}
