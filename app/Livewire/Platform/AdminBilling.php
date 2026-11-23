<?php

namespace App\Livewire\Platform;

use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Services\Platform\Efi\EfiClient;
use App\Services\Platform\Efi\EfiConnectionCheck;
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
 *
 * Messages: the connection test is a state, shown under the form method by method (what failed,
 * why, how to fix it) until the next test; the outcome of a click is a notification.
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
            $this->dispatch('warning', __('Saved, but Efí is not connected yet'), __('To charge, fill in the Client ID and the Client Secret of the application and click "Save and connect".'));
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
     * confirms payments manually starts charging through Efí once a payment method works.
     */
    private function checkConnection(bool $activate): void
    {
        try {
            $result = PlatformBilling::registerWebhooks();
        } catch (\Throwable $e) {
            $this->dispatch('error', __('The connection test could not run'), $e->getMessage());

            return;
        }
        $check = $result['check'];
        $environment = EfiConnectionCheck::environmentLabel($check['environment']);
        $description = __('See under the form what each payment method returned and how to fix it.');
        if (PlatformBilling::isManual() && $result['registered'] !== []) {
            if ($activate) {
                PlatformConfig::set('platform.billing.provider', PlatformBilling::PROVIDER);
                audit_log('platform.efi.activated', null, ['environment' => EfiSettings::environment()], 0);
                $description = __('Invoices are now charged through Efí.').' '.$description;
            } else {
                $description = __('Invoices are still confirmed manually: "Save and connect" makes Efí charge them.').' '.$description;
            }
        }
        match ($check['level']) {
            EfiConnectionCheck::OK => $this->dispatch('success', __('Efí connected (:env)', ['env' => $environment]), $description),
            EfiConnectionCheck::WARNING => $this->dispatch('warning', __('Efí connected in part (:env)', ['env' => $environment]), $description),
            default => $this->dispatch('error', __('Efí not connected (:env)', ['env' => $environment]), $description),
        };
    }

    public function markPaid(string $uuid): void
    {
        $this->guard();
        $invoice = $this->invoice($uuid);
        PlatformBilling::markPaid($invoice, $invoice->amount_cents, 'MANUAL', now(), 'user');
        $this->dispatch('success', __('Invoice :number marked as paid.', ['number' => $invoice->number]));
    }

    public function cancelInvoice(string $uuid): void
    {
        $this->guard();
        $invoice = $this->invoice($uuid);
        $this->attempt(__('Invoice :number could not be cancelled', ['number' => $invoice->number]), function () use ($invoice) {
            PlatformBilling::cancelInvoice($invoice);
            $this->dispatch('success', __('Invoice :number cancelled.', ['number' => $invoice->number]));
        });
    }

    public function syncInvoice(string $uuid): void
    {
        $this->guard();
        $invoice = $this->invoice($uuid);
        $this->attempt(__('Invoice :number could not be checked at the bank', ['number' => $invoice->number]), function () use ($invoice) {
            PlatformBilling::sync($invoice);
            $this->dispatch('success', __('Invoice :number checked at the bank: :status.', ['number' => $invoice->number, 'status' => __(ucfirst((string) $invoice->fresh()->status))]));
        });
    }

    public function reissue(string $uuid): void
    {
        $this->guard();
        $invoice = $this->invoice($uuid);
        $this->attempt(__('Invoice :number could not be issued again', ['number' => $invoice->number]), function () use ($invoice) {
            PlatformBilling::reissue($invoice);
            $this->dispatch('success', __('A new charge of invoice :number was issued.', ['number' => $invoice->number]));
        });
    }

    public function simulatePayment(string $uuid): void
    {
        $this->guard();
        $invoice = $this->invoice($uuid);
        $this->attempt(__('The payment could not be simulated'), function () use ($invoice) {
            PlatformBilling::simulatePayment($invoice);
            $this->dispatch('success', __('Payment simulated in the sandbox.'));
        });
    }

    public function runCycle(): void
    {
        $this->guard();
        $this->attempt(__('The billing cycle stopped with an error'), function () {
            PlatformBilling::runCycle();
            $this->dispatch('success', __('Billing cycle executed.'), __('Due renewals were issued and open invoices were checked at the bank.'));
        });
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
            'storedCertificate' => EfiSettings::hasStoredCertificate($this->environment),
            'inUse' => EfiSettings::environment() === EfiSettings::normalize($this->environment),
            'webhookAt' => PlatformSetting::get('efi.webhook_registered_at'),
            'lastWebhookAt' => PlatformSetting::get('efi.last_webhook_at'),
            'sandbox' => EfiSettings::isSandbox(),
            'check' => EfiConnectionCheck::last($this->environment),
            'certificate' => EfiSettings::certificateInfo($this->environment),
            'sameCertificate' => $this->sameCertificate(),
            'environmentLabel' => EfiConnectionCheck::environmentLabel($this->environment),
        ]);
    }

    /**
     * Deletes the certificate saved for the environment on screen.
     */
    public function removeCertificate(): void
    {
        $this->guard();
        $environment = EfiSettings::normalize($this->environment);
        if (! EfiSettings::hasStoredCertificate($environment)) {
            return;
        }
        EfiSettings::forgetCertificate($environment);
        EfiClient::forgetTokens();
        $this->reset('certificateFile', 'certificatePassword');
        audit_log('platform.efi.certificate_removed', null, ['environment' => $environment], 0);
        $this->dispatch('success', __('Certificate deleted (:env)', ['env' => EfiConnectionCheck::environmentLabel($environment)]),
            __('Without a certificate, the invoices of this environment are boletos with a Pix QR code until you send another one.'));
    }

    /**
     * The same certificate saved for both environments: each environment of Efí issues its own.
     */
    private function sameCertificate(): bool
    {
        $fingerprints = array_map(fn (string $environment) => EfiSettings::certificateInfo($environment)['fingerprint'] ?? null, EfiSettings::ENVIRONMENTS);

        return ! in_array(null, $fingerprints, true) && count(array_unique($fingerprints)) === 1;
    }

    /**
     * Runs an action and turns its failure into a notification with a title saying what failed.
     */
    private function attempt(string $failure, \Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            $this->dispatch('error', $failure, $e->getMessage());
        }
    }

    private function loadEnvironment(): void
    {
        $this->clientId = (string) EfiSettings::credential($this->environment, 'client_id');
        $this->pixKey = (string) EfiSettings::pixKey($this->environment);
    }

    private function invoice(string $uuid): PlatformInvoice
    {
        $invoice = PlatformInvoice::query()->withUuid($uuid)->first();
        abort_if(is_null($invoice), 404);

        return $invoice;
    }

    private function guard(): void
    {
        abort_unless(isPlatform() && auth()->check() && (int) currentTeam()?->id === 0 && auth()->user()->isInstanceAdmin(), 403);
    }
}
