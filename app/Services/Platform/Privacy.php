<?php

namespace App\Services\Platform;

use App\Jobs\PlatformPrivacyNoticeJob;
use App\Models\PlatformNotification;
use App\Models\PlatformSetting;
use App\Models\PrivacyConsent;
use App\Models\PrivacyPreference;
use App\Models\PrivacyRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * LGPD (Lei nº 13.709/2018) for the platform: who answers for the data, the published privacy
 * policy and its versions, the acceptances and consents of each user (art. 8), promotions only to
 * whom consented, and the requests of the data subjects (arts. 18 to 20).
 */
class Privacy
{
    /**
     * Announcements that are marketing: only for users who consented (art. 7º, I).
     */
    public const MARKETING_KINDS = [PlatformNotification::KIND_NEWS, PlatformNotification::KIND_PROMOTION];

    /**
     * Open requests a user may have at once.
     */
    public const MAX_OPEN_REQUESTS = 5;

    public static function controllerName(): string
    {
        return (string) (config('platform.privacy.controller_name') ?: config('platform.name', 'Hostrema'));
    }

    /**
     * Channel of the data subjects: the privacy contact, the data protection officer or support.
     */
    public static function contactEmail(): ?string
    {
        $email = config('platform.privacy.contact_email') ?: config('platform.privacy.dpo_email') ?: config('platform.support_email');

        return filled($email) ? (string) $email : null;
    }

    /**
     * @return array{name: ?string, email: ?string}
     */
    public static function officer(): array
    {
        return [
            'name' => filled(config('platform.privacy.dpo_name')) ? (string) config('platform.privacy.dpo_name') : null,
            'email' => filled(config('platform.privacy.dpo_email')) ? (string) config('platform.privacy.dpo_email') : null,
        ];
    }

    /**
     * What is still missing for the published policy to identify the controller and its channel
     * (art. 9º, III and IV; art. 41, § 1º).
     *
     * @return list<string>
     */
    public static function missingIdentification(): array
    {
        return array_values(array_filter([
            blank(config('platform.privacy.controller_name')) ? __('Legal name of the controller') : null,
            blank(config('platform.privacy.controller_document')) ? __('CNPJ or CPF of the controller') : null,
            is_null(self::contactEmail()) ? __('Privacy contact e-mail') : null,
        ]));
    }

    public static function policyVersion(): int
    {
        return max(1, (int) PlatformSetting::get('privacy.policy_version', 1));
    }

    public static function policyPublishedAt(): ?CarbonImmutable
    {
        $date = PlatformSetting::get('privacy.policy_published_at');

        return filled($date) ? CarbonImmutable::parse((string) $date) : null;
    }

    public static function policyChanges(): ?string
    {
        $changes = PlatformSetting::get('privacy.policy_changes');

        return filled($changes) ? (string) $changes : null;
    }

    /**
     * A new version of the privacy policy and terms of use: every user is told what changed, in
     * the bell, and accepts it on the next visit (art. 8º, § 6º; art. 9º, § 2º).
     */
    public static function publishPolicy(string $changes, ?User $author = null): int
    {
        $version = self::policyVersion() + 1;
        PlatformSetting::put('privacy.policy_version', (string) $version);
        PlatformSetting::put('privacy.policy_published_at', now()->toIso8601String());
        PlatformSetting::put('privacy.policy_changes', trim($changes));
        PlatformSetting::flushMemo();
        PlatformInbox::announce([
            'kind' => PlatformNotification::KIND_UPDATE,
            'title' => __('We updated the privacy policy and the terms of use'),
            'body' => trim($changes),
            'action_url' => route('platform.legal.privacy'),
            'action_label' => __('Read the privacy policy'),
            'audience' => PlatformNotification::AUDIENCE_ALL,
            'published_at' => now(),
        ], $author);
        audit_log('privacy.policy.published', null, ['version' => $version], 0);

        return $version;
    }

    public static function preferences(User $user): PrivacyPreference
    {
        return PrivacyPreference::query()->firstOrNew(['user_id' => $user->id]);
    }

    /**
     * The first account is the owner of the installation, the controller itself: it publishes the
     * policy instead of accepting it.
     */
    public static function needsPolicyAcceptance(User $user): bool
    {
        return $user->id !== 0 && self::preferences($user)->policy_version < self::policyVersion();
    }

    /**
     * Accounts from before the sign-up asked for it (or whose sign-up failed after the account was
     * created) never declared to be 18 or older (art. 14).
     */
    public static function needsAdultConfirmation(User $user): bool
    {
        return $user->id !== 0 && is_null(self::preferences($user)->adult_confirmed_at);
    }

    /**
     * The user read and accepts the current privacy policy and terms of use (and, when asked,
     * chose about promotions and declared to be 18 or older).
     */
    public static function acceptPolicy(User $user, ?bool $marketing = null, string $source = 'notice', bool $adult = false): void
    {
        $preferences = self::preferences($user);
        $confirmsAge = $adult && is_null($preferences->adult_confirmed_at);
        $preferences->fill(['policy_version' => self::policyVersion(), 'policy_accepted_at' => now()]);
        if ($confirmsAge) {
            $preferences->adult_confirmed_at = now();
        }
        $preferences->save();
        self::recordConsent($user, PrivacyConsent::PURPOSE_POLICY, true, $source);
        if ($confirmsAge) {
            self::recordConsent($user, PrivacyConsent::PURPOSE_ADULT, true, $source);
        }
        if (! is_null($marketing)) {
            self::setMarketing($user, $marketing, $source);
        }
    }

    /**
     * Consent to promotions and news, given or withdrawn at any time, free and easily (art. 8º,
     * § 5º; art. 18, IX).
     */
    public static function setMarketing(User $user, bool $granted, string $source = 'privacy'): void
    {
        $preferences = self::preferences($user);
        $changed = ! $preferences->exists || $preferences->marketing !== $granted;
        $preferences->fill(['marketing' => $granted, 'marketing_updated_at' => now()])->save();
        if ($changed) {
            self::recordConsent($user, PrivacyConsent::PURPOSE_MARKETING, $granted, $source);
        }
    }

    public static function wantsMarketing(User $user): bool
    {
        return (bool) PrivacyPreference::query()->whereKey($user->id)->value('marketing');
    }

    /**
     * Users who consented to promotions and news.
     */
    public static function consenting(Builder $users): Builder
    {
        return $users->whereIn('users.id', PrivacyPreference::query()->where('marketing', true)->select('user_id'));
    }

    /**
     * Sign-up: the policy and terms accepted, the age declared and the choice about promotions,
     * each with its proof.
     */
    public static function onRegistered(User $user, bool $marketing): void
    {
        $preferences = self::preferences($user);
        $preferences->fill([
            'policy_version' => self::policyVersion(),
            'policy_accepted_at' => now(),
            'adult_confirmed_at' => now(),
            'marketing' => $marketing,
            'marketing_updated_at' => now(),
        ])->save();
        self::recordConsent($user, PrivacyConsent::PURPOSE_POLICY, true, 'signup');
        self::recordConsent($user, PrivacyConsent::PURPOSE_ADULT, true, 'signup');
        self::recordConsent($user, PrivacyConsent::PURPOSE_MARKETING, $marketing, 'signup');
    }

    public static function recordConsent(User $user, string $purpose, bool $granted, ?string $source = null): void
    {
        // Outside a request (a job, a command) Laravel makes up one from 127.0.0.1: no address then.
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        PrivacyConsent::query()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'purpose' => $purpose,
            'granted' => $granted,
            'policy_version' => self::policyVersion(),
            'source' => $source,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? str((string) $request->userAgent())->limit(250, '')->value() : null,
        ]);
    }

    /**
     * A request of the data subject, answered by the controller within 15 days (art. 19, II). The
     * controller is told at once, by e-mail and in the bell of the operators.
     */
    public static function openRequest(User $user, string $type, ?string $message): PrivacyRequest
    {
        if (! in_array($type, PrivacyRequest::TYPES, true)) {
            throw ValidationException::withMessages(['requestType' => __('Choose what you want to ask.')]);
        }
        if (PrivacyRequest::query()->where('user_id', $user->id)->where('status', PrivacyRequest::STATUS_OPEN)->count() >= self::MAX_OPEN_REQUESTS) {
            throw ValidationException::withMessages(['requestType' => __('You already have :count open requests. Wait for the answers before opening another one.', ['count' => self::MAX_OPEN_REQUESTS])]);
        }
        $request = PrivacyRequest::query()->create([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'type' => $type,
            'message' => filled($message) ? trim((string) $message) : null,
        ]);
        audit_log('privacy.request.opened', $request, ['type' => $type], 0);
        $title = __('New LGPD request: :type', ['type' => PrivacyRequest::typeLabel($type)]);
        $lines = [
            __(':name (:email) asked: :type (:article).', ['name' => $user->name, 'email' => $user->email, 'type' => PrivacyRequest::typeLabel($type), 'article' => PrivacyRequest::typeArticle($type)]),
            $request->message,
            __('Answer by :date (15 days, LGPD art. 19).', ['date' => $request->due_at->format('d/m/Y')]),
        ];
        PlatformInbox::announce([
            'kind' => PlatformNotification::KIND_ALERT,
            'title' => str($title)->limit(157)->value(),
            'body' => implode("\n", array_filter($lines)),
            'action_url' => route('admin.platform', ['tab' => 'privacy']),
            'action_label' => __('Answer'),
            'audience' => PlatformNotification::AUDIENCE_OPERATORS,
            'published_at' => now(),
        ]);
        $controller = array_values(array_unique(array_filter([self::contactEmail(), self::officer()['email']])));
        if ($controller !== []) {
            PlatformPrivacyNoticeJob::dispatch($controller, $title, array_values(array_filter($lines)), route('admin.platform', ['tab' => 'privacy']), __('Answer'));
        }

        return $request;
    }

    /**
     * The answer reaches the data subject by e-mail and stays on the privacy page.
     */
    public static function answerRequest(PrivacyRequest $request, string $answer, User $author): void
    {
        $request->forceFill([
            'status' => PrivacyRequest::STATUS_ANSWERED,
            'answer' => trim($answer),
            'answered_by' => $author->id,
            'answered_at' => now(),
        ])->save();
        audit_log('privacy.request.answered', $request, ['type' => $request->type, 'late' => $request->answered_at->gt($request->due_at)], 0);
        PlatformPrivacyNoticeJob::dispatch([$request->email], __('Answer to your request: :type', ['type' => PrivacyRequest::typeLabel($request->type)]), [
            __('You asked on :date: :type.', ['date' => $request->created_at->format('d/m/Y'), 'type' => PrivacyRequest::typeLabel($request->type)]),
            $request->answer,
        ], route('platform.privacy'), __('Privacy and data'));
    }

    /**
     * Who else processes personal data for the platform, and why (art. 9º, V; art. 18, VII).
     *
     * @return list<array{name: string, purpose: string, data: string, location: string}>
     */
    public static function processors(): array
    {
        $processors = [[
            'name' => (string) config('platform.privacy.hosting'),
            'purpose' => __('Servers, databases and backups of the platform and of the applications of the customers'),
            'data' => __('All the data of the platform'),
            'location' => __('As described'),
        ]];
        $mail = self::mailProvider();
        if ($mail) {
            $processors[] = [
                'name' => $mail,
                'purpose' => __('Sending the e-mails of the platform'),
                'data' => __('Name, e-mail and the content of each message'),
                'location' => str_contains(strtolower($mail), 'amazon') ? __('Same as the servers') : __('According to the provider'),
            ];
        }
        $provider = (string) config('platform.billing.provider');
        if ($provider === PlatformBilling::PROVIDER) {
            $processors[] = [
                'name' => 'Efí S.A. (Efí Bank)',
                'purpose' => __('Issuing and receiving the Pix and boleto charges'),
                'data' => __('Name, CPF or CNPJ, e-mail, phone and the amount of each invoice'),
                'location' => __('Brazil'),
            ];
        } elseif ($provider === 'stripe') {
            $processors[] = [
                'name' => 'Stripe, Inc.',
                'purpose' => __('Card payments'),
                'data' => __('Name, e-mail and card data, typed straight into Stripe'),
                'location' => __('United States (international transfer, art. 33)'),
            ];
        }

        return $processors;
    }

    private static function mailProvider(): ?string
    {
        $settings = instanceSettings();
        if ($settings->resend_enabled) {
            return 'Resend';
        }
        if (! $settings->smtp_enabled) {
            return null;
        }
        $host = strtolower((string) $settings->smtp_host);

        return match (true) {
            str_contains($host, 'amazonaws.com') => 'Amazon Simple Email Service (Amazon SES)',
            $host === '' => null,
            default => __('E-mail provider (:host)', ['host' => $host]),
        };
    }
}
