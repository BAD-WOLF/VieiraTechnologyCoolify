<?php

namespace App\Livewire\Platform\Concerns;

use App\Models\PlatformInvoice;
use App\Models\PlatformTenant;
use App\Services\Platform\BillingProfile;
use App\Services\Platform\Efi\EfiSettings;
use App\Services\Platform\InvoiceUsageExport;
use App\Services\Platform\PlatformBilling;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Tenant actions on the invoices (Efí or manual) and the billing profile (Pricing and Billing
 * pages). Downloads go through Livewire actions, so they also work before the plan is active.
 */
trait ManagesInvoices
{
    /** @var array<string, string> */
    public array $profile = [];

    public bool $editingProfile = false;

    /**
     * Last unpaid invoice shown on the page: when it gets paid, the page says so.
     */
    public ?string $watchedInvoice = null;

    protected function loadProfile(PlatformTenant $tenant): void
    {
        $this->profile = BillingProfile::of($tenant);
        $this->profile['payment_method'] = (string) BillingProfile::methodOf($tenant);
        if (blank($this->profile['email'] ?? null)) {
            $this->profile['email'] = (string) auth()->user()?->email;
        }
        $this->editingProfile = ! BillingProfile::isComplete($tenant);
    }

    public function saveProfile(): void
    {
        if (! $this->canManageBilling()) {
            return;
        }
        $this->validate(BillingProfile::rules('profile.', $this->profile['payment_method'] ?? null));
        $tenant = platformTenant();
        BillingProfile::save($tenant, $this->profile);
        $tenant = $tenant->fresh();
        $method = BillingProfile::methodOf($tenant);
        PlatformInvoice::query()->where('team_id', $tenant->team_id)->where('status', PlatformInvoice::STATUS_OPEN)->get()
            ->each(function (PlatformInvoice $invoice) use ($method) {
                if (blank($invoice->provider_reference)) {
                    $metadata = (array) $invoice->metadata;
                    unset($metadata['issue_attempts']);
                    $invoice->forceFill(['metadata' => $metadata])->save();

                    return;
                }
                // A charge of the other method is replaced, so the customer pays the way just chosen.
                $issuedAs = PlatformBilling::isPix($invoice) ? BillingProfile::PIX : (PlatformBilling::isBoleto($invoice) ? BillingProfile::BOLETO : null);
                if (! is_null($method) && ! is_null($issuedAs) && $issuedAs !== $method) {
                    PlatformBilling::reissue($invoice);
                }
            });
        $this->editingProfile = false;
        $this->dispatch('success', __('Billing details saved.'));
    }

    /**
     * Pay an open invoice with the other method: boleto asks for the phone number first.
     */
    public function payWith(string $method): void
    {
        if (! $this->canManageBilling() || ! in_array($method, BillingProfile::methods(), true)) {
            return;
        }
        try {
            $tenant = platformTenant();
            $this->loadProfile($tenant);
            $this->profile['payment_method'] = $method;
            if (validator($this->profile, BillingProfile::rules('', $method))->fails()) {
                $this->editingProfile = true;

                return;
            }
            $this->saveProfile();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function refreshInvoice(string $uuid): void
    {
        try {
            $invoice = $this->teamInvoice($uuid);
            if ($invoice->provider === PlatformBilling::PROVIDER && filled($invoice->provider_reference)
                && RateLimiter::attempt('platform-invoice-refresh:'.$invoice->id, 6, fn () => true, 60)) {
                PlatformBilling::sync($invoice);
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Polled while an invoice waits for payment. The webhook usually confirms it first; the bank
     * is asked at most every 20 seconds (every 5 while a boleto is still being registered).
     */
    public function checkPayment(string $uuid): void
    {
        try {
            $invoice = $this->teamInvoice($uuid);
            if ($invoice->status !== PlatformInvoice::STATUS_OPEN || $invoice->provider !== PlatformBilling::PROVIDER || blank($invoice->provider_reference)) {
                return;
            }
            $seconds = PlatformBilling::hasPaymentData($invoice) ? 20 : 5;
            if (RateLimiter::attempt('platform-invoice-check:'.$invoice->id, 1, fn () => true, $seconds)) {
                PlatformBilling::sync($invoice);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The watched invoice, once paid.
     */
    protected function paidWatchedInvoice(): ?PlatformInvoice
    {
        if (blank($this->watchedInvoice)) {
            return null;
        }

        return PlatformInvoice::query()->withUuid($this->watchedInvoice)->where('team_id', currentTeam()?->id)
            ->where('status', PlatformInvoice::STATUS_PAID)->first();
    }

    public function downloadBoleto(string $uuid)
    {
        try {
            $invoice = $this->teamInvoice($uuid);
            $url = (string) data_get($invoice->payment_details, 'boleto_url', '');
            if ($invoice->provider !== PlatformBilling::PROVIDER || ! PlatformBilling::isBoleto($invoice) || ! str_starts_with($url, 'https://')) {
                $this->dispatch('error', __('The boleto is not available yet.'));

                return null;
            }

            return redirect()->away($url);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function downloadUsage(string $uuid)
    {
        $invoice = $this->teamInvoice($uuid);

        return response()->streamDownload(fn () => InvoiceUsageExport::write($invoice), 'uso-'.($invoice->number ?? $invoice->uuid).'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Tests only (Settings > Billing): pays a manual invoice, or an Efí sandbox charge.
     */
    public function simulatePayment(string $uuid): void
    {
        if (! $this->canManageBilling() || ! PlatformBilling::allowsSimulatedPayments()) {
            return;
        }
        try {
            $invoice = $this->teamInvoice($uuid);
            if (PlatformBilling::isManualInvoice($invoice)) {
                PlatformBilling::markPaid($invoice, $invoice->amount_cents, 'SIMULATED', now(), 'user');
            } elseif (filled($invoice->provider_reference) && EfiSettings::isSandbox()) {
                PlatformBilling::simulatePayment($invoice);
            } else {
                $this->dispatch('error', __('Payments can only be simulated with the manual provider or the Efí sandbox.'));

                return;
            }
            $this->dispatch('success', __('Payment simulated.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function reissueInvoice(string $uuid): void
    {
        if (! $this->canManageBilling()) {
            return;
        }
        try {
            PlatformBilling::reissue($this->teamInvoice($uuid));
            $this->dispatch('success', __('A new charge was issued.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    protected function teamInvoice(string $uuid): PlatformInvoice
    {
        $invoice = PlatformInvoice::query()->withUuid($uuid)->where('team_id', currentTeam()?->id)->first();
        abort_if(is_null($invoice), 404);

        return $invoice;
    }

    protected function canManageBilling(): bool
    {
        if (! isPlatformTenant() || ! auth()->user()?->isAdmin()) {
            $this->dispatch('error', __('Only team owners and admins can manage the subscription.'));

            return false;
        }

        return true;
    }

    public static function pixQrSvg(?string $payload): ?string
    {
        if (blank($payload)) {
            return null;
        }
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($payload);

        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }
}
