<?php

namespace App\Livewire\Platform;

use App\Livewire\Help;
use App\Models\Team;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;

/**
 * The "Feedback" form of the dashboard. Coolify sends it to the Coolify team (its cloud API or
 * feedback@coollabs.io); on the platform it goes to the operator's support e-mail, with the
 * customer as the reply-to address.
 */
class PlatformHelp extends Help
{
    public function submit()
    {
        if (! isPlatform()) {
            return parent::submit();
        }
        try {
            $this->validate();
            $this->rateLimit(3, 30);
            $support = self::supportEmail();
            if (is_null($support) || blank(set_transanctional_email_settings(instanceSettings()))) {
                $this->dispatch('error', __('Support is not available by e-mail yet. Please try again later.'));

                return;
            }
            $user = auth()->user();
            $team = currentTeam();
            $mail = new MailMessage;
            $mail->view('emails.help', [
                'description' => __('From: :name <:email>, team ":team" (#:id).', [
                    'name' => (string) $user?->name, 'email' => (string) $user?->email, 'team' => (string) $team?->name, 'id' => (string) $team?->id,
                ])."\n\n".$this->description,
            ]);
            $subject = '['.config('platform.name').'] '.$this->subject;
            Mail::send([], [], fn (Message $message) => $message
                ->to($support)
                ->replyTo((string) $user?->email, (string) $user?->name)
                ->subject($subject)
                ->html((string) $mail->render()));
            audit_log('platform.support.message', null, ['subject' => $this->subject], $team?->id);
            $this->dispatch('success', __('Message sent.'), __('We will get in touch with you as soon as possible.'));
            $this->reset('description', 'subject');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * The support e-mail of the platform, or the owner of the platform team.
     */
    public static function supportEmail(): ?string
    {
        $configured = trim((string) config('platform.support_email'));
        if ($configured !== '') {
            return $configured;
        }
        $owner = Team::find(0)?->members()->wherePivot('role', 'owner')->orderBy('users.id')->first();

        return filled($owner?->email) ? (string) $owner->email : null;
    }
}
