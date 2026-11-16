<?php

namespace App\Livewire\Platform;

use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Services\Platform\BillingProfile;
use App\Services\Platform\Inter\InterClient;
use App\Services\Platform\Inter\InterSettings;
use App\Services\Platform\InterBilling;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PlatformConfig;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Operator console, "Billing" tab: Banco Inter credentials, plan prices, webhook and invoices.
 * Secrets are write-only: they are never sent back to the browser.
 */
class AdminBilling extends Component
{
    use WithFileUploads;

    public string $environment = 'production';

    public string $clientId = '';

    public string $clientSecret = '';

    public string $certificate = '';

    public string $privateKey = '';

    public $certificateFile = null;

    public $privateKeyFile = null;

    public string $contaCorrente = '';

    public string $pixKey = '';

    /** @var array<string, array<string, string>> */
    public array $prices = [];

    public ?string $result = null;

    public bool $resultOk = true;

    public string $search = '';

    public function mount(): void
    {
        $this->guard();
        $this->environment = InterSettings::environment();
        $this->clientId = (string) InterSettings::value('client_id');
        $this->contaCorrente = (string) InterSettings::value('conta_corrente');
        $this->pixKey = (string) InterSettings::pixKey();
        foreach (array_keys(PlanCatalog::all()) as $plan) {
            foreach ([PlanCatalog::INTERVAL_MONTHLY, PlanCatalog::INTERVAL_YEARLY] as $interval) {
                $cents = InterBilling::priceCents($plan, $interval);
                $this->prices[$plan][$interval] = $cents ? number_format($cents / 100, 2, ',', '.') : '';
            }
        }
    }

    public function save(): void
    {
        $this->guard();
        $this->validate([
            'environment' => ['required', 'in:production,sandbox'],
            'clientId' => ['nullable', 'string', 'max:200'],
            'clientSecret' => ['nullable', 'string', 'max:500'],
            'certificate' => ['nullable', 'string', 'max:20000'],
            'privateKey' => ['nullable', 'string', 'max:20000'],
            'certificateFile' => ['nullable', 'file', 'max:64'],
            'privateKeyFile' => ['nullable', 'file', 'max:64'],
            'contaCorrente' => ['nullable', 'string', 'max:30'],
            'pixKey' => ['nullable', 'string', 'max:77'],
            'prices.*.*' => ['nullable', 'string', 'max:20'],
        ]);
        $certificate = $this->certificateFile ? (string) file_get_contents($this->certificateFile->getRealPath()) : trim($this->certificate);
        $privateKey = $this->privateKeyFile ? (string) file_get_contents($this->privateKeyFile->getRealPath()) : trim($this->privateKey);
        if ($certificate !== '' && ! str_contains($certificate, 'BEGIN CERTIFICATE')) {
            $this->addError('certificate', __('This is not a certificate (.crt) in PEM format.'));

            return;
        }
        if ($privateKey !== '' && ! preg_match('/BEGIN (RSA |EC )?PRIVATE KEY/', $privateKey)) {
            $this->addError('privateKey', __('This is not a private key (.key) in PEM format.'));

            return;
        }

        InterSettings::store('environment', $this->environment);
        InterSettings::store('client_id', trim($this->clientId) ?: null);
        InterSettings::store('conta_corrente', trim($this->contaCorrente) ?: null);
        InterSettings::store('pix_key', trim($this->pixKey) ?: null);
        if (trim($this->clientSecret) !== '') {
            InterSettings::store('client_secret', trim($this->clientSecret));
        }
        if ($certificate !== '') {
            InterSettings::store('certificate', $certificate);
        }
        if ($privateKey !== '') {
            InterSettings::store('private_key', $privateKey);
        }
        foreach ($this->prices as $plan => $intervals) {
            if (! PlanCatalog::exists($plan)) {
                continue;
            }
            foreach ((array) $intervals as $interval => $value) {
                if (! in_array($interval, [PlanCatalog::INTERVAL_MONTHLY, PlanCatalog::INTERVAL_YEARLY], true)) {
                    continue;
                }
                $cents = InterBilling::toCents($value);
                $path = "platform.plans.{$plan}.price_{$interval}";
                $cents ? PlatformConfig::set($path, number_format($cents / 100, 2, '.', '')) : PlatformConfig::reset($path);
                PlatformSetting::put("price.{$plan}.{$interval}", null);
            }
        }
        $this->reset('clientSecret', 'certificate', 'privateKey', 'certificateFile', 'privateKeyFile');
        InterClient::forgetTokens();
        audit_log('platform.inter.settings_saved', null, ['environment' => $this->environment], 0);

        if (InterSettings::isConfigured()) {
            $this->connect();
        } else {
            $this->report(true, __('Saved. Fill in the remaining Banco Inter credentials to enable payments.'));
        }
    }

    /**
     * Registers the webhooks (each API with its own token): if it works, payments are ready.
     */
    public function connect(): void
    {
        $this->guard();
        try {
            InterClient::forgetTokens();
            $result = InterBilling::registerWebhooks();
            $labels = [BillingProfile::PIX => 'Pix', BillingProfile::BOLETO => __('Boleto')];
            $failures = collect($result['errors'])->map(fn (string $error, string $method) => ($labels[$method] ?? $method).': '.$error)->values()->all();
            if ($result['registered'] === []) {
                $this->report(false, implode(' ', $failures));

                return;
            }
            $message = __('Connected to Banco Inter (:env). Webhook registered for :methods: :url', [
                'env' => InterSettings::environment(),
                'methods' => collect($result['registered'])->map(fn (string $method) => $labels[$method] ?? $method)->implode(', '),
                'url' => preg_replace('#/[^/]+$#', '/•••', $result['url']),
            ]);
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
        InterBilling::markPaid($invoice, $invoice->amount_cents, 'MANUAL', now(), 'user');
        $this->report(true, __('Invoice :number marked as paid.', ['number' => $invoice->number]));
    }

    public function cancelInvoice(string $uuid): void
    {
        $this->guard();
        InterBilling::cancelInvoice($this->invoice($uuid));
    }

    public function syncInvoice(string $uuid): void
    {
        $this->guard();
        try {
            InterBilling::sync($this->invoice($uuid));
        } catch (\Throwable $e) {
            $this->report(false, $e->getMessage());
        }
    }

    public function reissue(string $uuid): void
    {
        $this->guard();
        try {
            InterBilling::reissue($this->invoice($uuid));
        } catch (\Throwable $e) {
            $this->report(false, $e->getMessage());
        }
    }

    public function simulatePayment(string $uuid): void
    {
        $this->guard();
        try {
            InterBilling::simulatePayment($this->invoice($uuid));
            $this->report(true, __('Payment simulated in the sandbox.'));
        } catch (\Throwable $e) {
            $this->report(false, $e->getMessage());
        }
    }

    public function runCycle(): void
    {
        $this->guard();
        try {
            InterBilling::runCycle();
            $this->report(true, __('Billing cycle executed.'));
        } catch (\Throwable $e) {
            $this->report(false, $e->getMessage());
        }
    }

    public function render()
    {
        $invoices = PlatformInvoice::query()
            ->with('team:id,name')
            ->where('provider', InterBilling::PROVIDER)
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('number', 'ilike', '%'.$this->search.'%')
                    ->orWhereHas('team', fn ($query) => $query->where('name', 'ilike', '%'.$this->search.'%'));
            }))
            ->latest('id')
            ->limit(50)
            ->get();

        return view('livewire.platform.admin-billing', [
            'invoices' => $invoices,
            'enabled' => InterBilling::enabled(),
            'manual' => InterBilling::isManual(),
            'configured' => InterSettings::isConfigured(),
            'hasSecret' => filled(InterSettings::value('client_secret')),
            'hasCertificate' => filled(InterSettings::pem('certificate')),
            'hasKey' => filled(InterSettings::pem('private_key')),
            'webhookAt' => PlatformSetting::get('inter.webhook_registered_at'),
            'lastWebhookAt' => PlatformSetting::get('inter.last_webhook_at'),
            'plans' => PlanCatalog::all(),
            'sandbox' => InterSettings::isSandbox(),
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
