<?php

namespace App\Livewire\Platform;

use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Services\Platform\BillingProfile;
use App\Services\Platform\Efi\EfiClient;
use App\Services\Platform\Efi\EfiException;
use App\Services\Platform\Efi\EfiSettings;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\PlatformConfig;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Operator console, "Billing" tab: Efí Bank credentials, the Pix webhook and the invoices (prices
 * are in the Plans tab). Secrets are write-only: they are never sent back to the browser.
 *
 * Each environment (Produção, Homologação) keeps its own credentials: choosing one in the form
 * shows what is saved for it, and saving makes it the one in use.
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
        $this->loadEnvironment();
    }

    /**
     * Another environment chosen in the form: its own saved values replace the ones on screen.
     */
    public function updatedEnvironment(): void
    {
        $this->guard();
        if (! in_array($this->environment, EfiSettings::ENVIRONMENTS, true)) {
            $this->environment = EfiSettings::environment();
        }
        $this->reset('clientSecret', 'certificateFile', 'certificatePassword');
        $this->resetValidation();
        $this->loadEnvironment();
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

        $environment = EfiSettings::normalize($this->environment);
        EfiSettings::store('environment', $environment);
        EfiSettings::storeCredential($environment, 'client_id', trim($this->clientId) ?: null);
        EfiSettings::storeCredential($environment, 'pix_key', trim($this->pixKey) ?: null);
        if (trim($this->clientSecret) !== '') {
            EfiSettings::storeCredential($environment, 'client_secret', trim($this->clientSecret));
        }
        if (! is_null($pem)) {
            EfiSettings::storeCredential($environment, 'certificate', $pem[0]);
            EfiSettings::storeCredential($environment, 'private_key', $pem[1]);
        }
        $this->reset('clientSecret', 'certificateFile', 'certificatePassword');
        EfiClient::forgetTokens();
        audit_log('platform.efi.settings_saved', null, ['environment' => $environment, 'certificate' => ! is_null($pem)], 0);

        if (EfiSettings::isConfigured()) {
            $this->checkConnection(activate: true);
        } else {
            $this->report(true, __('Saved. Fill in the Client ID and the Client Secret of the Efí application to charge.'));
        }
    }

    /**
     * Checks the credentials of each API and registers the Pix webhook, without changing how the
     * invoices are charged.
     */
    public function connect(): void
    {
        $this->guard();
        $this->checkConnection(activate: false);
    }

    /**
     * Connection test of the environment in use. With $activate (Save and connect), a platform that
     * confirms payments manually starts charging through Efí once the connection works.
     */
    private function checkConnection(bool $activate): void
    {
        if (! EfiSettings::isConfigured()) {
            $this->report(false, __('Fill in the Client ID and the Client Secret of the Efí application.'));

            return;
        }
        try {
            EfiClient::forgetTokens();
            $result = PlatformBilling::registerWebhooks();
        } catch (\Throwable $e) {
            $this->report(false, $e->getMessage());

            return;
        }
        $labels = [BillingProfile::PIX => 'Pix', BillingProfile::BOLETO => __('Boleto')];
        $failures = collect($result['errors'])->map(fn (string $error, string $method) => ($labels[$method] ?? $method).': '.$error)->values()->all();
        if ($result['registered'] === []) {
            $this->report(false, implode(' ', $failures));

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
        if (PlatformBilling::isManual()) {
            if ($activate) {
                PlatformConfig::set('platform.billing.provider', PlatformBilling::PROVIDER);
                audit_log('platform.efi.activated', null, ['environment' => EfiSettings::environment()], 0);
                $message .= ' '.__('Invoices are now charged through Efí.');
            } else {
                $message .= ' '.__('Invoices are still confirmed manually: "Save and connect" makes Efí charge them.');
            }
        }
        $this->report($failures === [], $message);
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
            'configured' => EfiSettings::isConfigured($this->environment),
            'pixReady' => EfiSettings::pixReady($this->environment),
            'hasSecret' => filled(EfiSettings::credential($this->environment, 'client_secret')),
            'hasCertificate' => filled(EfiSettings::pem('certificate', $this->environment)),
            'inUse' => EfiSettings::environment() === EfiSettings::normalize($this->environment),
            'webhookAt' => PlatformSetting::get('efi.webhook_registered_at'),
            'lastWebhookAt' => PlatformSetting::get('efi.last_webhook_at'),
            'sandbox' => EfiSettings::isSandbox(),
        ]);
    }

    private function loadEnvironment(): void
    {
        $this->clientId = (string) EfiSettings::credential($this->environment, 'client_id');
        $this->pixKey = (string) EfiSettings::pixKey($this->environment);
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
