<?php

namespace App\Jobs;

use App\Models\PlatformInvoice;
use App\Services\Platform\Inter\InterSettings;
use App\Services\Platform\InterBilling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reads an invoice's charge from Banco Inter. While the bank is still generating the boleto/Pix
 * data the job checks again every 10 seconds (up to 2 minutes).
 */
class PlatformInterSyncInvoiceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 30;

    public function __construct(public int $invoiceId, public int $attempt = 0)
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
        if (! $invoice || $invoice->provider !== InterBilling::PROVIDER || ! InterSettings::isConfigured()) {
            return;
        }
        $invoice = InterBilling::sync($invoice);
        if ($invoice->status === PlatformInvoice::STATUS_OPEN && ! InterBilling::hasPaymentData($invoice) && $this->attempt < 12) {
            self::dispatch($invoice->id, $this->attempt + 1)->delay(now()->addSeconds(10));
        }
    }
}
