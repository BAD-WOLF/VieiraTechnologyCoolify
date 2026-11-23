<?php

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\PlatformInvoice;
use App\Models\PlatformTenant;
use App\Models\PrivacyConsent;
use App\Models\PrivacyRequest;
use App\Models\User;

/**
 * The personal data of a user, for the data subject (LGPD, art. 18, I, II and V; art. 19): a
 * summary shown at once and a complete copy, in JSON, that other services can read.
 */
class PrivacyExport
{
    /**
     * Summary shown at once on the privacy page (art. 19, I).
     *
     * @return list<array{label: string, value: string}>
     */
    public static function summary(User $user): array
    {
        $preferences = Privacy::preferences($user);
        $lastAccess = AuditLog::query()->where('user_id', $user->id)->where('action', 'auth.login')->latest('id')->first();
        $teams = $user->teams()->where('teams.id', '!=', 0)->get();
        $tenant = $teams->map(fn ($team) => PlatformTenant::query()->where('team_id', $team->id)->first())->filter()->first(fn (PlatformTenant $tenant) => filled($tenant->billing_profile));
        $profile = (array) ($tenant?->billing_profile ?? []);
        $document = (string) ($profile['document'] ?? '');

        return [
            ['label' => __('Name'), 'value' => (string) $user->name],
            ['label' => __('E-mail'), 'value' => (string) $user->email],
            ['label' => __('Account created'), 'value' => (string) $user->created_at?->format('d/m/Y H:i')],
            ['label' => __('Two-factor authentication'), 'value' => filled($user->two_factor_confirmed_at) ? __('On') : __('Off')],
            ['label' => __('Last access'), 'value' => $lastAccess ? $lastAccess->created_at->format('d/m/Y H:i').' ('.$lastAccess->ip_address.')' : __('No record')],
            ['label' => __('Teams'), 'value' => $teams->pluck('name')->implode(', ') ?: __('None')],
            ['label' => __('Payer data'), 'value' => $profile === [] ? __('None') : trim(($profile['name'] ?? '').' · '.($document !== '' ? str_repeat('•', max(0, strlen($document) - 4)).substr($document, -4) : ''), ' ·')],
            ['label' => __('Invoices'), 'value' => (string) PlatformInvoice::query()->whereIn('team_id', $teams->pluck('id'))->where('status', '!=', PlatformInvoice::STATUS_DRAFT)->count()],
            ['label' => __('Promotions and news'), 'value' => $preferences->marketing ? __('You agreed to receive them') : __('You do not receive them')],
            ['label' => __('Privacy policy accepted'), 'value' => $preferences->policy_accepted_at ? __('Version :version, on :date', ['version' => $preferences->policy_version, 'date' => $preferences->policy_accepted_at->format('d/m/Y')]) : __('Not yet')],
        ];
    }

    /**
     * Complete copy of the personal data (art. 19, § 3º; portability, art. 18, V).
     *
     * @return array<string, mixed>
     */
    public static function forUser(User $user): array
    {
        $teams = $user->teams()->where('teams.id', '!=', 0)->get();
        $preferences = Privacy::preferences($user);

        return [
            'generated_at' => now()->toIso8601String(),
            'controller' => [
                'name' => Privacy::controllerName(),
                'document' => config('platform.privacy.controller_document'),
                'contact' => Privacy::contactEmail(),
            ],
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => filled($user->two_factor_confirmed_at),
            ],
            'privacy' => [
                'marketing' => (bool) $preferences->marketing,
                'policy_version' => $preferences->policy_version,
                'policy_accepted_at' => $preferences->policy_accepted_at?->toIso8601String(),
                'adult_confirmed_at' => $preferences->adult_confirmed_at?->toIso8601String(),
                'consents' => PrivacyConsent::query()->where('user_id', $user->id)->oldest('id')->get()
                    ->map(fn (PrivacyConsent $consent) => [
                        'purpose' => $consent->purpose,
                        'granted' => $consent->granted,
                        'policy_version' => $consent->policy_version,
                        'at' => $consent->created_at?->toIso8601String(),
                        'ip_address' => $consent->ip_address,
                    ])->all(),
            ],
            'teams' => $teams->map(fn ($team) => self::team($team, $team->pivot->role))->all(),
            'requests' => PrivacyRequest::query()->where('user_id', $user->id)->oldest('id')->get()
                ->map(fn (PrivacyRequest $request) => [
                    'type' => $request->type,
                    'message' => $request->message,
                    'status' => $request->status,
                    'created_at' => $request->created_at?->toIso8601String(),
                    'answer' => $request->answer,
                    'answered_at' => $request->answered_at?->toIso8601String(),
                ])->all(),
            'access_records' => AuditLog::query()->where('user_id', $user->id)->where('action', 'like', 'auth.%')->latest('id')->limit(5000)->get()
                ->map(fn (AuditLog $log) => ['at' => $log->created_at?->toIso8601String(), 'action' => $log->action, 'ip_address' => $log->ip_address, 'user_agent' => $log->user_agent])->all(),
            'activity' => AuditLog::query()->where('user_id', $user->id)->where('action', 'not like', 'auth.%')->latest('id')->limit(5000)->get()
                ->map(fn (AuditLog $log) => ['at' => $log->created_at?->toIso8601String(), 'action' => $log->action, 'ip_address' => $log->ip_address])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function team($team, string $role): array
    {
        $tenant = PlatformTenant::query()->where('team_id', $team->id)->first();
        $manages = in_array($role, ['owner', 'admin'], true);

        return [
            'name' => $team->name,
            'role' => $role,
            'plan' => $tenant?->plan,
            'status' => $tenant?->status,
            // Payer data and invoices only to whom manages the team.
            'payer' => $manages ? (array) ($tenant?->billing_profile ?? []) : null,
            'invoices' => $manages ? PlatformInvoice::query()->where('team_id', $team->id)->where('status', '!=', PlatformInvoice::STATUS_DRAFT)->oldest('id')->get()
                ->map(fn (PlatformInvoice $invoice) => [
                    'number' => $invoice->number,
                    'period_start' => $invoice->period_start?->toDateString(),
                    'period_end' => $invoice->period_end?->toDateString(),
                    'amount_cents' => $invoice->amount_cents,
                    'currency' => $invoice->currency,
                    'status' => $invoice->status,
                    'paid_at' => $invoice->paid_at?->toIso8601String(),
                ])->all() : null,
            'resources' => collect(TenantLifecycle::workloads($team->id))->map(fn ($resource) => [
                'type' => class_basename($resource),
                'name' => $resource->name,
                'domains' => $resource->fqdn ?? null,
                'created_at' => $resource->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
