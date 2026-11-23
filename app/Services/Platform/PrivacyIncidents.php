<?php

namespace App\Services\Platform;

use App\Jobs\PlatformPrivacyNoticeJob;
use App\Models\PlatformNotification;
use App\Models\PrivacyIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Security incidents with personal data (LGPD, art. 48; Resolução CD/ANPD nº 15/2024): the
 * register, the communication to the data subjects (by e-mail, one by one, and in the bell) with
 * what the law asks, and the record of the communication to the ANPD, made on its own form.
 */
class PrivacyIncidents
{
    /**
     * Communicates the data subjects. Returns how many users were told.
     */
    public static function communicate(PrivacyIncident $incident, string $audience, ?User $author = null): int
    {
        $users = self::audience($audience);
        $count = (clone $users)->count();
        $lines = self::lines($incident);
        $title = __('Security incident: :title', ['title' => $incident->title]);
        (clone $users)->select('users.email')->orderBy('users.id')->chunk(200, function ($chunk) use ($title, $lines) {
            PlatformPrivacyNoticeJob::dispatch($chunk->pluck('email')->all(), $title, $lines, route('platform.privacy'), __('Privacy and data'));
        });
        PlatformInbox::announce([
            'kind' => PlatformNotification::KIND_ALERT,
            'title' => str($title)->limit(157)->value(),
            'body' => str(implode("\n", $lines))->limit(4000)->value(),
            'action_url' => route('platform.privacy'),
            'action_label' => __('Privacy and data'),
            'audience' => match ($audience) {
                'customers' => PlatformNotification::AUDIENCE_CUSTOMERS,
                'operators' => PlatformNotification::AUDIENCE_OPERATORS,
                default => PlatformNotification::AUDIENCE_ALL,
            },
            'published_at' => now(),
        ], $author);
        $incident->forceFill([
            'communicated_audience' => $audience,
            'titulares_communicated_at' => now(),
            'titulares_communicated_count' => $count,
        ])->save();
        audit_log('privacy.incident.communicated', $incident, ['audience' => $audience, 'users' => $count], 0);

        return $count;
    }

    /**
     * What the communication to the data subjects says (art. 48, § 1º; Resolução CD/ANPD nº
     * 15/2024, art. 9º).
     *
     * @return list<string>
     */
    public static function lines(PrivacyIncident $incident): array
    {
        $contact = Privacy::officer()['email'] ?? Privacy::contactEmail();

        return array_values(array_filter([
            __('What happened: :text', ['text' => $incident->description]),
            __('When we learned of it: :date.', ['date' => $incident->known_at->format('d/m/Y')]),
            __('Personal data involved: :text', ['text' => $incident->data_categories]),
            $incident->risks ? __('Risks and possible consequences for you: :text', ['text' => $incident->risks]) : null,
            $incident->security_measures ? __('How the data was protected: :text', ['text' => $incident->security_measures]) : null,
            $incident->mitigation ? __('What we did and will do: :text', ['text' => $incident->mitigation]) : null,
            $incident->recommendations ? __('What we recommend you to do: :text', ['text' => $incident->recommendations]) : null,
            $incident->delay_reason ? __('Why this notice took longer: :text', ['text' => $incident->delay_reason]) : null,
            $contact ? __('Questions: :email.', ['email' => $contact]) : null,
        ]));
    }

    public static function audience(string $audience): Builder
    {
        $users = User::query();

        return match ($audience) {
            'customers' => $users->whereHas('teams', fn (Builder $query) => $query->where('teams.id', '!=', 0)),
            'operators' => $users->whereHas('teams', fn (Builder $query) => $query->where('teams.id', 0)),
            default => $users,
        };
    }
}
