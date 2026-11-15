<?php

namespace App\Livewire\Platform\Concerns;

use App\Models\PlatformInvoice;
use App\Models\PlatformTenant;
use App\Services\Platform\BillingProfile;
use App\Services\Platform\Inter\InterSettings;
use App\Services\Platform\InterBilling;
use App\Services\Platform\InvoiceUsageExport;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Tenant actions on Banco Inter invoices and the billing profile (Pricing and Billing pages).
 * Downloads go through Livewire actions, so they also work before the plan is active.
 */
trait ManagesInterInvoices
{
    /** @var array<string, string> */
    public array $profile = [];

    public bool $editingProfile = false;

    protected function loadProfile(PlatformTenant $tenant): void
    {
        $this->profile = BillingProfile::of($tenant);
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
        $this->validate(BillingProfile::rules('profile.'));
        $tenant = platformTenant();
        BillingProfile::save($tenant, $this->profile);
        PlatformInvoice::query()->where('team_id', $tenant->team_id)->where('status', PlatformInvoice::STATUS_OPEN)
            ->whereNull('provider_reference')->get()
            ->each(function (PlatformInvoice $invoice) {
                $metadata = (array) $invoice->metadata;
                unset($metadata['issue_attempts']);
                $invoice->forceFill(['metadata' => $metadata])->save();
            });
        $this->editingProfile = false;
        $this->dispatch('success', __('Billing details saved.'));
    }

    public function refreshInvoice(string $uuid): void
    {
        try {
            $invoice = $this->teamInvoice($uuid);
            if ($invoice->provider === InterBilling::PROVIDER && filled($invoice->provider_reference)
                && RateLimiter::attempt('platform-invoice-refresh:'.$invoice->id, 6, fn () => true, 60)) {
                InterBilling::sync($invoice);
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function downloadBoleto(string $uuid)
    {
        try {
            $invoice = $this->teamInvoice($uuid);
            if ($invoice->provider !== InterBilling::PROVIDER || blank($invoice->provider_reference)) {
                $this->dispatch('error', __('The boleto is not available yet.'));

                return null;
            }
            $pdf = Cache::remember('platform:inter:pdf:'.$invoice->provider_reference, 600, fn () => base64_encode(InterBilling::client()->chargePdf($invoice->provider_reference)));

            return response()->streamDownload(function () use ($pdf) {
                echo base64_decode($pdf);
            }, 'boleto-'.$invoice->number.'.pdf', ['Content-Type' => 'application/pdf']);
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
     * Tests only (Settings > Billing): pays a manual invoice, or an Inter sandbox charge.
     */
    public function simulatePayment(string $uuid): void
    {
        if (! $this->canManageBilling() || ! InterBilling::allowsSimulatedPayments()) {
            return;
        }
        try {
            $invoice = $this->teamInvoice($uuid);
            if (InterBilling::isManualInvoice($invoice)) {
                InterBilling::markPaid($invoice, $invoice->amount_cents, 'SIMULATED', now(), 'user');
            } elseif (filled($invoice->provider_reference) && InterSettings::isSandbox()) {
                InterBilling::client()->simulatePayment((string) $invoice->provider_reference, 'PIX');
                InterBilling::sync($invoice);
            } else {
                $this->dispatch('error', __('Payments can only be simulated with the manual provider or the Inter sandbox.'));

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
            InterBilling::reissue($this->teamInvoice($uuid));
            $this->dispatch('success', __('A new charge was issued.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    protected function teamInvoice(string $uuid): PlatformInvoice
    {
        $invoice = PlatformInvoice::query()->where('uuid', $uuid)->where('team_id', currentTeam()?->id)->first();
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
