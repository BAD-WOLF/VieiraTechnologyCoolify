<?php

namespace App\Livewire\Platform;

use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Services\Platform\BillingProfile;
use App\Services\Platform\Efi\EfiClient;
use App\Services\Platform\Efi\EfiException;
use App\Services\Platform\Efi\EfiSettings;
use App\Services\Platform\PlatformBilling;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Operator console, "Billing" tab: Efí Bank credentials, the Pix webhook and the invoices (prices
 * are in the Plans tab). Secrets are write-only: they are never sent back to the browser.
 */
class AdminBilling extends Component
{
    use WithFileUploads;

    public string $environment = 'production';

    public string $clientId = '';

    public string $clientSecret = '';

    /** Certificate of the Efí application: the .p12 downloaded from Efí or its .pem. */
    public $certificateFile = null;

    public string $certificatePassword = '';

    public string $pixKey = '';

    public ?string $result = null;

    public bool $resultOk = true;

    public string $search = '';

    public function mount(): void
    {
        $this->guard();
        $this->environment = EfiSettings::environment();
        $this->clientId = (string) EfiSettings::value('client_id');
        $this->pixKey = (string) EfiSettings::pixKey();
    }

    public function save(): void
    {
        $this->guard();
        $this->validate([
            'environment' => ['required', 'in:production,sandbox'],
            'clientId' => ['nullable', 'string', 'max:200'],
            'clientSecret' => ['nullable', 'string', 'max:500'],
            'certificateFile' => ['nullable', 'file', 'max:64'],
            'certificatePassword' => ['nullable', 'string', 'max:200'],
            'pixKey' => ['nullable', 'string', 'max:77'],
        ]);
        $pem = null;
        if ($this->certificateFile) {
            try {
                $pem = EfiSettings::readCertificate((string) file_get_contents($this->certificateFile->getRealPath()), $this->certificatePassword);
            } catch (EfiException $e) {
                $this->addError('certificateFile', $e->getMessage());

                return;
            }
        }

        EfiSettings::store('environment', $this->environment);
        EfiSettings::store('client_id', trim($this->clientId) ?: null);
        EfiSettings::store('pix_key', trim($this->pixKey) ?: null);
        if (trim($this->clientSecret) !== '') {
            EfiSettings::store('client_secret', trim($this->clientSecret));
        }
        if (! is_null($pem)) {
            EfiSettings::store('certificate', $pem[0]);
            EfiSettings::store('private_key', $pem[1]);
        }
        $this->reset('clientSecret', 'certificateFile', 'certificatePassword');
        EfiClient::forgetTokens();
        audit_log('platform.efi.settings_saved', null, ['environment' => $this->environment, 'certificate' => ! is_null($pem)], 0);

        if (EfiSettings::isConfigured()) {
            $this->connect();
        } else {
            $this->report(true, __('Saved. Fill in the Client ID and the Client Secret of the Efí application to charge.'));
        }
    }

    /**
     * Checks the credentials of each API and registers the Pix webhook: if it works, payments are ready.
     */
    public function connect(): void
    {
        $this->guard();
        try {
            EfiClient::forgetTokens();
            $result = PlatformBilling::registerWebhooks();
            $labels = [BillingProfile::PIX => 'Pix', BillingProfile::BOLETO => __('Boleto')];
            $failures = collect($result['errors'])->map(fn (string $error, string $method) => ($labels[$method] ?? $method).': '.$error)->values()->all();
            if ($result['registered'] === []) {
                $this->report(false, $failures === [] ? __('Fill in the Client ID and the Client Secret of the Efí application.') : implode(' ', $failures));

                return;
            }
            $message = __('Connected to Efí (:env). Ready to charge with :methods.', [
                'env' => EfiSettings::isSandbox() ? __('sandbox') : __('production'),
                'methods' => collect($result['registered'])->map(fn (string $method) => $labels[$method] ?? $method)->implode(', '),
            ]);
            if (in_array(BillingProfile::PIX, $result['registered'], true)) {
                $message .= ' '.__('Pix notifications: :url', ['url' => preg_replace('#/[^/]+$#', '/•••', $result['url'])]);
            } elseif (! EfiSettings::pixReady()) {
                $message .= ' '.__('For Pix charges, also send the certificate of the application and the Pix key.');
            }
            if ($failures !== []) {
                $message .= ' '.__('Not available: :errors', ['errors' => implode(' ', $failures)]);
            }
            $this->report($failures === [], $message);
        } catch (\Throwable $e) {
            $this->report(false, $e->getMessage());
        }
    }

    public function markPaid(string $uuid): void
    {
        $this->guard();
        $invoice = $this->invoice($uuid);
        PlatformBilling::markPaid($invoice, $invoice->amount_cents, 'MANUAL', now(), 'user');
        $this->report(true, __('Invoice :number marked as paid.', ['number' => $invoice->number]));
    }

    public function cancelInvoice(string $uuid): void
    {
        $this->guard();
        PlatformBilling::cancelInvoice($this->invoice($uuid));
    }

    public function syncInvoice(string $uuid): void
    {
        $this->guard();
        try {
            PlatformBilling::sync($this->invoice($uuid));
        } catch (\Throwable $e) {
            $this->report(false, $e->getMessage());
        }
    }

    public function reissue(string $uuid): void
    {
        $this->guard();
        try {
            PlatformBilling::reissue($this->invoice($uuid));
        } catch (\Throwable $e) {
            $this->report(false, $e->getMessage());
        }
    }

    public function simulatePayment(string $uuid): void
    {
        $this->guard();
        try {
            PlatformBilling::simulatePayment($this->invoice($uuid));
            $this->report(true, __('Payment simulated in the sandbox.'));
        } catch (\Throwable $e) {
            $this->report(false, $e->getMessage());
        }
    }

    public function runCycle(): void
    {
        $this->guard();
        try {
            PlatformBilling::runCycle();
            $this->report(true, __('Billing cycle executed.'));
        } catch (\Throwable $e) {
            $this->report(false, $e->getMessage());
        }
    }

    public function render()
    {
        $invoices = PlatformInvoice::query()
            ->with('team:id,name')
            ->where('provider', PlatformBilling::PROVIDER)
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('number', 'ilike', '%'.$this->search.'%')
                    ->orWhereHas('team', fn ($query) => $query->where('name', 'ilike', '%'.$this->search.'%'));
            }))
            ->latest('id')
            ->limit(50)
            ->get();

        return view('livewire.platform.admin-billing', [
            'invoices' => $invoices,
            'enabled' => PlatformBilling::enabled(),
            'manual' => PlatformBilling::isManual(),
            'configured' => EfiSettings::isConfigured(),
            'pixReady' => EfiSettings::pixReady(),
            'hasSecret' => filled(EfiSettings::value('client_secret')),
            'hasCertificate' => filled(EfiSettings::pem('certificate')),
            'webhookAt' => PlatformSetting::get('efi.webhook_registered_at'),
            'lastWebhookAt' => PlatformSetting::get('efi.last_webhook_at'),
            'sandbox' => EfiSettings::isSandbox(),
        ]);
    }

    private function report(bool $ok, string $message): void
    {
        $this->resultOk = $ok;
        $this->result = $message;
    }

    private function invoice(string $uuid): PlatformInvoice
    {
        $invoice = PlatformInvoice::query()->where('uuid', $uuid)->first();
        abort_if(is_null($invoice), 404);

        return $invoice;
    }

    private function guard(): void
    {
        abort_unless(isPlatform() && auth()->check() && (int) currentTeam()?->id === 0 && auth()->user()->isInstanceAdmin(), 403);
    }
}
