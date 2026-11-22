<?php

namespace App\Jobs;

use App\Models\PlatformInvoice;
use App\Services\Platform\Efi\EfiSettings;
use App\Services\Platform\PlatformBilling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reads an invoice's charge from Efí. While Efí is still generating the payment data the job
 * checks again every 10 seconds (up to 2 minutes).
 */
class PlatformSyncInvoiceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 30;

    /**
     * @param  int|null  $amountPaidCents  amount received, when a notification brought it
     */
    public function __construct(public int $invoiceId, public int $attempt = 0, public ?int $amountPaidCents = null)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        $invoice = PlatformInvoice::query()->find($this->invoiceId);
        if (! $invoice || $invoice->provider !== PlatformBilling::PROVIDER || ! EfiSettings::isConfigured()) {
            return;
        }
        $invoice = PlatformBilling::sync($invoice, $this->amountPaidCents);
        if ($invoice->status === PlatformInvoice::STATUS_OPEN && ! PlatformBilling::hasPaymentData($invoice) && $this->attempt < 12) {
            self::dispatch($invoice->id, $this->attempt + 1)->delay(now()->addSeconds(10));
        }
    }
}
