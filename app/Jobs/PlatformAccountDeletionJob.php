<?php

namespace App\Jobs;

use App\Models\PrivacyDeletedAccount;
use App\Services\Platform\AccountDeletion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deletes an account in the background (LGPD, art. 18, VI): see AccountDeletion.
 */
class PlatformAccountDeletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 3600;

    public function __construct(public int $recordId)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $record = PrivacyDeletedAccount::query()->find($this->recordId);
        if (is_null($record) || $record->status !== PrivacyDeletedAccount::STATUS_PENDING) {
            return;
        }
        AccountDeletion::execute($record);
    }
}
