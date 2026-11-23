<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * E-mails a privacy notice (LGPD) to the given addresses: the answer to a request, a deleted
 * account, a security incident or a new request for the controller.
 */
class PlatformPrivacyNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /**
     * @param  list<string>  $emails
     * @param  array<int, string|null>  $lines
     */
    public function __construct(
        public array $emails,
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
            'lines' => array_values(array_filter($this->lines, 'filled')),
            'actionUrl' => $this->actionUrl,
            'actionText' => $this->actionText ?? __('Open'),
        ]);
        foreach (array_unique($this->emails) as $email) {
            try {
                send_user_an_email($mail, $email);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
