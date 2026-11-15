<?php

namespace App\Jobs;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * E-mails a platform notice (quota, billing, suspension) to the owners and admins of a tenant team.
 */
class PlatformNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public Team $team,
        public string $subject,
        public array $lines,
        public ?string $actionUrl = null,
        public ?string $actionText = null,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $mail = new MailMessage;
        $mail->subject('['.config('platform.name').'] '.$this->subject);
        $mail->view('emails.platform-notice', [
            'title' => $this->subject,
            'lines' => $this->lines,
            'actionUrl' => $this->actionUrl,
            'actionText' => $this->actionText ?? __('Open'),
        ]);
        foreach ($this->team->members as $member) {
            if (! $member->isAdmin() && $member->pivot?->role !== 'owner') {
                continue;
            }
            try {
                send_user_an_email($mail, $member->email);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
