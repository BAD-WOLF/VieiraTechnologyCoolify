<?php

namespace App\Services\Platform;

use App\Actions\Platform\ProvisionTenant;
use App\Jobs\PlatformInterSyncInvoiceJob;
use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Models\PlatformTenant;
use App\Models\Subscription;
use App\Services\Platform\Inter\InterClient;
use App\Services\Platform\Inter\InterSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Subscriptions charged through Banco Inter (boleto + Pix).
 *
 * - Checkout: an invoice for the first period is issued as an Inter charge; the plan starts when it is paid.
 * - Renewal: at the end of each period an invoice with the next period and the usage overage of the
 *   closed period is issued, due in `platform.billing.due_days`; unpaid invoices make the tenant
 *   past due and TenantLifecycle suspends it after 7 days and stops it after 30 days.
 * - Upgrades are charged pro rata and applied when paid; downgrades and interval changes apply on
 *   the next renewal; cancellation ends the subscription at the end of the period.
 * - Payment confirmation comes from the Inter webhook (re-checked through the API, never trusted
 *   as is) and from a periodic reconciliation of the open invoices.
 */
class InterBilling
{
    public const PROVIDER = 'inter';

    public const PAID_STATUSES = ['RECEBIDO', 'MARCADO_RECEBIDO'];

    public const VOID_STATUSES = ['CANCELADO', 'EXPIRADO', 'FALHA_EMISSAO'];

    private const MAX_ISSUE_ATTEMPTS = 5;

    /**
     * Provider "manual": same invoices and cycle, without the bank. The customer pays by Pix or
     * transfer following the instructions of the operator, who marks the invoice as paid.
     */
    public const MANUAL = 'manual';

    public static function enabled(): bool
    {
        return in_array(config('platform.billing.provider', self::PROVIDER), [self::PROVIDER, self::MANUAL], true);
    }

    public static function isManual(): bool
    {
        return config('platform.billing.provider') === self::MANUAL;
    }

    public static function ready(): bool
    {
        return self::enabled() && (self::isManual() || InterSettings::isConfigured());
    }

    public static function allowsSimulatedPayments(): bool
    {
        return self::enabled() && (bool) config('platform.billing.allow_simulated_payments', false);
    }

    public static function client(): InterClient
    {
        return app(InterClient::class);
    }

    public static function priceCents(?string $plan, ?string $interval): ?int
    {
        if (! PlanCatalog::exists($plan)) {
            return null;
        }
        $interval = $interval === PlanCatalog::INTERVAL_YEARLY ? PlanCatalog::INTERVAL_YEARLY : PlanCatalog::INTERVAL_MONTHLY;
        // Settings > Plans (or the Billing tab) override the config; older consoles stored "price.*".
        $legacy = PlatformConfig::isOverridden("platform.plans.{$plan}.price_{$interval}") ? null : PlatformSetting::get("price.{$plan}.{$interval}");

        return self::toCents(filled($legacy) ? $legacy : data_get(PlanCatalog::get($plan), "price_{$interval}"));
    }

    /**
     * "49.90", "49,90", "1.499,00", 1499.0 -> cents.
     */
    public static function toCents(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $cents = (int) round(((float) $value) * 100);

            return $cents > 0 ? $cents : null;
        }
        $text = (string) preg_replace('/[^\d,.]/', '', trim((string) $value));
        if ($text === '') {
            return null;
        }
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = strrpos($text, ',') > strrpos($text, '.')
                ? str_replace(',', '.', str_replace('.', '', $text))
                : str_replace(',', '', $text);
        } elseif (str_contains($text, ',')) {
            $text = str_replace(',', '.', $text);
        }
        if (! is_numeric($text)) {
            return null;
        }
        $cents = (int) round(((float) $text) * 100);

        return $cents > 0 ? $cents : null;
    }

    public static function hasActivePlan(PlatformTenant $tenant): bool
    {
        return $tenant->hasPlan() && ! in_array($tenant->status, [PlatformTenant::STATUS_PENDING, PlatformTenant::STATUS_CANCELLED], true);
    }

    /**
     * New subscription, upgrade (pro rata), or change scheduled for the next renewal.
     *
     * @return array{action: string, message: string, invoice: ?PlatformInvoice}
     */
    public static function subscribe(PlatformTenant $tenant, string $plan, string $interval): array
    {
        $interval = $interval === PlanCatalog::INTERVAL_YEARLY ? PlanCatalog::INTERVAL_YEARLY : PlanCatalog::INTERVAL_MONTHLY;
        $price = self::priceCents($plan, $interval);
        if (is_null($price) || data_get(PlanCatalog::get($plan), 'contact_sales')) {
            throw new \RuntimeException(__('This plan is not available for self-service checkout.'));
        }
        if (! BillingProfile::isComplete($tenant)) {
            throw new \RuntimeException(__('Fill in the billing details first.'));
        }

        if (! self::hasActivePlan($tenant)) {
            self::voidOpenInvoices($tenant, PlatformInvoice::TYPE_SUBSCRIPTION);
            $start = CarbonImmutable::now();
            $invoice = self::createInvoice($tenant, PlatformInvoice::TYPE_SUBSCRIPTION, $plan, $interval, $start, self::periodEnd($start, $interval), [
                self::planLine($plan, $interval, $price),
            ]);
            self::issue($invoice);

            return ['action' => 'checkout', 'message' => self::isManual() ? __('Invoice issued. Follow the payment instructions to activate the plan.') : __('Invoice issued. Pay with Pix or boleto to activate the plan.'), 'invoice' => $invoice->fresh()];
        }

        $currentInterval = $tenant->billing_interval ?: PlanCatalog::INTERVAL_MONTHLY;
        if ($plan === $tenant->plan && $interval === $currentInterval) {
            $tenant->update(['pending_plan' => null, 'pending_interval' => null, 'cancel_at_period_end' => false]);
            self::syncCoolifySubscription($tenant->fresh());

            return ['action' => 'kept', 'message' => __('You are already on this plan.'), 'invoice' => null];
        }

        $currentPrice = (int) self::priceCents($tenant->plan, $currentInterval);
        if ($interval === $currentInterval && $price > $currentPrice && $tenant->current_period_end?->isFuture()) {
            $amount = self::proratedDifference($tenant, $currentPrice, $price);
            if ($amount < (int) config('platform.billing.min_charge_cents', 250)) {
                $tenant->update(['plan' => $plan, 'pending_plan' => null, 'pending_interval' => null]);
                audit_log('platform.plan.upgraded', $tenant, ['plan' => $plan, 'charged_cents' => 0], $tenant->team_id);

                return ['action' => 'upgraded', 'message' => __('Plan changed.'), 'invoice' => null];
            }
            self::voidOpenInvoices($tenant, PlatformInvoice::TYPE_UPGRADE);
            $invoice = self::createInvoice($tenant, PlatformInvoice::TYPE_UPGRADE, $plan, $interval, CarbonImmutable::now(), $tenant->current_period_end->toImmutable(), [[
                'code' => 'upgrade',
                'description' => __('Upgrade to :plan (proportional to the rest of the period)', ['plan' => PlanCatalog::get($plan)['name'] ?? $plan]),
                'quantity' => 1,
                'unit' => 'proration',
                'amount_cents' => $amount,
            ]]);
            self::issue($invoice);

            return ['action' => 'upgrade', 'message' => __('Pay the proportional difference to switch to the new plan now.'), 'invoice' => $invoice->fresh()];
        }

        $tenant->update(['pending_plan' => $plan, 'pending_interval' => $interval, 'cancel_at_period_end' => false]);
        audit_log('platform.plan.change_scheduled', $tenant, ['plan' => $plan, 'interval' => $interval], $tenant->team_id);

        return [
            'action' => 'scheduled',
            'message' => __('The change takes effect on the next renewal (:date).', ['date' => $tenant->current_period_end?->format('d/m/Y') ?? '-']),
            'invoice' => null,
        ];
    }

    public static function cancelAtPeriodEnd(PlatformTenant $tenant, bool $cancel = true): void
    {
        $tenant->update(['cancel_at_period_end' => $cancel, 'pending_plan' => $cancel ? null : $tenant->pending_plan]);
        self::syncCoolifySubscription($tenant->fresh());
        audit_log($cancel ? 'platform.subscription.cancel_requested' : 'platform.subscription.resumed', $tenant, [], $tenant->team_id);
    }

    public static function proratedDifference(PlatformTenant $tenant, int $currentPrice, int $newPrice): int
    {
        $start = $tenant->current_period_start?->getTimestamp() ?? now()->getTimestamp();
        $end = $tenant->current_period_end?->getTimestamp() ?? now()->getTimestamp();
        $total = max(1, $end - $start);
        $remaining = max(0, min($total, $end - now()->getTimestamp()));

        return (int) round(max(0, $newPrice - $currentPrice) * $remaining / $total);
    }

    public static function periodEnd(CarbonInterface $start, string $interval): CarbonImmutable
    {
        $start = CarbonImmutable::instance($start);

        return $interval === PlanCatalog::INTERVAL_YEARLY ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
    }

    /**
     * @return array{code: string, description: string, quantity: int, unit: string, amount_cents: int}
     */
    public static function planLine(string $plan, string $interval, int $price): array
    {
        return [
            'code' => 'plan',
            'description' => __('Plan :plan (:interval)', [
                'plan' => PlanCatalog::get($plan)['name'] ?? $plan,
                'interval' => $interval === PlanCatalog::INTERVAL_YEARLY ? __('yearly') : __('monthly'),
            ]),
            'quantity' => 1,
            'unit' => $interval,
            'amount_cents' => $price,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function createInvoice(PlatformTenant $tenant, string $type, string $plan, string $interval, CarbonInterface $start, CarbonInterface $end, array $lines): PlatformInvoice
    {
        $lines = array_values(array_filter($lines, fn (array $line) => ($line['code'] ?? '') === 'plan' || (int) ($line['amount_cents'] ?? 0) > 0));
        $invoice = PlatformInvoice::create([
            'team_id' => $tenant->team_id,
            'type' => $type,
            'provider' => self::PROVIDER,
            'plan' => $plan,
            'billing_interval' => $interval,
            'period_start' => $start,
            'period_end' => $end,
            'amount_cents' => array_sum(array_map(fn (array $line) => (int) $line['amount_cents'], $lines)),
            'currency' => 'brl',
            'status' => PlatformInvoice::STATUS_OPEN,
            'lines' => $lines,
            'due_date' => now()->addDays(max(1, (int) config('platform.billing.due_days', 5)))->toDateString(),
        ]);
        $invoice->forceFill(['number' => 'HR'.str_pad(strtoupper(base_convert((string) $invoice->id, 10, 36)), 8, '0', STR_PAD_LEFT)])->save();
        audit_log('platform.invoice.created', $invoice, ['type' => $type, 'plan' => $plan, 'amount_cents' => $invoice->amount_cents], $tenant->team_id, 'system');

        return $invoice;
    }

    /**
     * Registers the invoice at Banco Inter (boleto + Pix). The payment data arrives asynchronously.
     */
    public static function issue(PlatformInvoice $invoice): PlatformInvoice
    {
        if ($invoice->status === PlatformInvoice::STATUS_PAID) {
            return $invoice;
        }
        if ($invoice->amount_cents <= 0) {
            self::markPaid($invoice, 0, 'NONE');

            return $invoice->fresh();
        }
        if (self::isManual()) {
            return self::issueManually($invoice);
        }
        $tenant = PlatformTenant::forTeam($invoice->team_id);
        $billing = (array) config('platform.billing', []);
        $payload = [
            'seuNumero' => $invoice->number,
            'valorNominal' => round($invoice->amount_cents / 100, 2),
            'dataVencimento' => $invoice->due_date->toDateString(),
            'numDiasAgenda' => max(0, min(60, (int) ($billing['cancel_after_days'] ?? 30))),
            'pagador' => BillingProfile::payer($tenant),
            'mensagem' => [
                'linha1' => Str::limit(config('platform.name').' - '.self::describe($invoice), 78, ''),
                'linha2' => Str::limit(__('Period').': '.$invoice->period_start->format('d/m/Y').' - '.$invoice->period_end->format('d/m/Y'), 78, ''),
            ],
        ];
        if (($fine = (float) ($billing['fine_percent'] ?? 0)) > 0) {
            $payload['multa'] = ['codigo' => 'PERCENTUAL', 'taxa' => round($fine, 2)];
        }
        if (($interest = (float) ($billing['interest_percent_month'] ?? 0)) > 0) {
            $payload['mora'] = ['codigo' => 'TAXAMENSAL', 'taxa' => round($interest, 2)];
        }

        $metadata = (array) $invoice->metadata;
        try {
            $code = self::client()->createCharge($payload);
        } catch (\Throwable $e) {
            $metadata['issue_attempts'] = (int) ($metadata['issue_attempts'] ?? 0) + 1;
            $metadata['issue_error'] = Str::limit($e->getMessage(), 500);
            $invoice->forceFill(['metadata' => $metadata])->save();
            audit_log('platform.invoice.issue_failed', $invoice, ['error' => $metadata['issue_error']], $invoice->team_id, 'system');
            throw $e;
        }
        $metadata['references'] = array_values(array_unique([...(array) ($metadata['references'] ?? []), $code]));
        unset($metadata['issue_error'], $metadata['issue_attempts']);
        $invoice->forceFill([
            'provider' => self::PROVIDER,
            'provider_reference' => $code,
            'provider_status' => 'EM_PROCESSAMENTO',
            'status' => PlatformInvoice::STATUS_OPEN,
            'payment_details' => null,
            'metadata' => $metadata,
        ])->save();
        audit_log('platform.invoice.issued', $invoice, ['reference' => $code], $invoice->team_id, 'system');
        PlatformInterSyncInvoiceJob::dispatch($invoice->id)->delay(now()->addSeconds(5));

        return $invoice;
    }

    /**
     * Invoice of the manual provider: open at once, with the payment instructions of the operator.
     */
    private static function issueManually(PlatformInvoice $invoice): PlatformInvoice
    {
        $metadata = array_merge((array) $invoice->metadata, ['manual' => true]);
        unset($metadata['issue_error'], $metadata['issue_attempts']);
        $invoice->forceFill([
            'provider' => self::PROVIDER,
            'provider_reference' => null,
            'provider_status' => 'MANUAL',
            'status' => PlatformInvoice::STATUS_OPEN,
            'payment_details' => ['instructions' => (string) config('platform.billing.manual_instructions')],
            'metadata' => $metadata,
            'synced_at' => now(),
        ])->save();
        audit_log('platform.invoice.issued', $invoice, ['manual' => true], $invoice->team_id, 'system');
        self::notifyIssued($invoice);

        return $invoice;
    }

    public static function isManualInvoice(PlatformInvoice $invoice): bool
    {
        return (bool) data_get($invoice->metadata, 'manual', false) && blank($invoice->provider_reference);
    }

    /**
     * Reads the charge from Banco Inter and applies its status to the invoice.
     */
    public static function sync(PlatformInvoice $invoice): PlatformInvoice
    {
        if (blank($invoice->provider_reference)) {
            return $invoice;
        }
        $data = self::client()->charge($invoice->provider_reference);
        $charge = (array) data_get($data, 'cobranca', []);
        $status = (string) data_get($charge, 'situacao', '');
        $details = array_filter([
            'linha_digitavel' => data_get($data, 'boleto.linhaDigitavel'),
            'codigo_barras' => data_get($data, 'boleto.codigoBarras'),
            'nosso_numero' => data_get($data, 'boleto.nossoNumero'),
            'txid' => data_get($data, 'pix.txid'),
            'pix_copia_e_cola' => data_get($data, 'pix.pixCopiaECola'),
        ], fn ($value) => filled($value));
        $hadDetails = self::hasPaymentData($invoice);
        $invoice->forceFill([
            'provider_status' => $status !== '' ? $status : $invoice->provider_status,
            'payment_details' => array_merge((array) $invoice->payment_details, $details),
            'synced_at' => now(),
        ])->save();

        if (in_array($status, self::PAID_STATUSES, true)) {
            self::markPaid(
                $invoice,
                self::toCents(data_get($charge, 'valorTotalRecebido')) ?? $invoice->amount_cents,
                data_get($charge, 'origemRecebimento'),
                self::parseDate(data_get($charge, 'dataSituacao')),
            );
        } elseif (in_array($status, self::VOID_STATUSES, true) && $invoice->status === PlatformInvoice::STATUS_OPEN) {
            $invoice->forceFill([
                'status' => PlatformInvoice::STATUS_VOID,
                'metadata' => array_merge((array) $invoice->metadata, ['void_reason' => $status, 'motivo' => data_get($charge, 'motivoCancelamento')]),
            ])->save();
            audit_log('platform.invoice.void', $invoice, ['status' => $status], $invoice->team_id, 'system');
        } elseif (! $hadDetails && $details !== [] && $invoice->status === PlatformInvoice::STATUS_OPEN) {
            self::notifyIssued($invoice->fresh());
        }
        if ($status === 'ATRASADO') {
            self::flagOverdue($invoice->fresh());
        }

        return $invoice->fresh();
    }

    public static function hasPaymentData(PlatformInvoice $invoice): bool
    {
        return filled(data_get($invoice->payment_details, 'pix_copia_e_cola')) || filled(data_get($invoice->payment_details, 'linha_digitavel'));
    }

    public static function markPaid(PlatformInvoice $invoice, ?int $amountPaidCents = null, ?string $method = null, ?CarbonInterface $paidAt = null, string $actorType = 'system'): void
    {
        $paid = DB::transaction(function () use ($invoice, $amountPaidCents, $method, $paidAt) {
            $locked = PlatformInvoice::query()->lockForUpdate()->find($invoice->id);
            if (! $locked || $locked->status === PlatformInvoice::STATUS_PAID) {
                return null;
            }
            $locked->forceFill([
                'status' => PlatformInvoice::STATUS_PAID,
                'paid_at' => $paidAt ?? now(),
                'amount_paid_cents' => $amountPaidCents ?? $locked->amount_cents,
                'payment_method' => $method,
            ])->save();

            return $locked;
        });
        if (! $paid) {
            return;
        }

        $tenant = PlatformTenant::forTeam($paid->team_id);
        $wasPending = $tenant->status === PlatformTenant::STATUS_PENDING || ! $tenant->hasPlan();
        $wasStopped = in_array($tenant->status, [PlatformTenant::STATUS_CANCELLED, PlatformTenant::STATUS_SUSPENDED, PlatformTenant::STATUS_HARD_SUSPENDED], true)
            && in_array($tenant->suspension_reason, [TenantLifecycle::REASON_OVERDUE, TenantLifecycle::REASON_CANCELLED, null], true);

        if ($paid->type === PlatformInvoice::TYPE_SUBSCRIPTION) {
            $updates = [
                'plan' => $paid->plan,
                'billing_interval' => $paid->billing_interval,
                'current_period_start' => $paid->period_start,
                'current_period_end' => $paid->period_end,
                'billing_provider' => self::PROVIDER,
                'pending_plan' => null,
                'pending_interval' => null,
                'cancel_at_period_end' => false,
            ];
            if (! self::hasOverdueInvoices($tenant->team_id)) {
                $updates['past_due_since'] = null;
            }
            $tenant->update($updates);
        } elseif ($paid->type === PlatformInvoice::TYPE_UPGRADE && PlanCatalog::exists($paid->plan)) {
            $tenant->update(['plan' => $paid->plan]);
        }
        $tenant = $tenant->fresh();

        if ($wasPending) {
            $tenant->update(['status' => PlatformTenant::STATUS_ACTIVE, 'suspension_reason' => null, 'suspended_at' => null]);
            if (is_null($tenant->provisioned_at)) {
                ProvisionTenant::dispatch($tenant->team_id);
            }
        } elseif ($wasStopped && is_null($tenant->past_due_since)) {
            TenantLifecycle::reactivate($tenant);
        }
        $tenant = $tenant->fresh();
        self::syncCoolifySubscription($tenant);
        audit_log('platform.invoice.paid', $paid, [
            'amount_paid_cents' => $paid->amount_paid_cents,
            'method' => $method,
            'type' => $paid->type,
        ], $paid->team_id, $actorType);
        TenantLifecycle::notify($tenant, __('Payment confirmed'), array_values(array_filter([
            __('We received the payment of invoice :number (:amount). Thank you!', ['number' => $paid->number, 'amount' => formatPlatformMoney((int) $paid->amount_paid_cents, 'brl')]),
            $wasPending ? __('Thank you! Your plan is being activated; this can take a few seconds.') : null,
        ])));
    }

    /**
     * Coolify (cloud mode) opens the dashboard only for teams with a paid subscription record.
     */
    public static function syncCoolifySubscription(PlatformTenant $tenant): void
    {
        Subscription::query()->updateOrCreate(['team_id' => $tenant->team_id], [
            'stripe_invoice_paid' => self::hasActivePlan($tenant),
            'stripe_cancel_at_period_end' => (bool) $tenant->cancel_at_period_end,
        ]);
    }

    public static function hasOverdueInvoices(int $teamId): bool
    {
        return PlatformInvoice::query()
            ->where('team_id', $teamId)
            ->where('provider', self::PROVIDER)
            ->where('type', PlatformInvoice::TYPE_SUBSCRIPTION)
            ->where('status', PlatformInvoice::STATUS_OPEN)
            ->whereDate('due_date', '<', today())
            ->exists();
    }

    /**
     * Overdue renewal: the tenant becomes past due (suspension after 7 days, stop after 30 days).
     */
    public static function flagOverdue(PlatformInvoice $invoice): void
    {
        if ($invoice->status !== PlatformInvoice::STATUS_OPEN || $invoice->type !== PlatformInvoice::TYPE_SUBSCRIPTION || ! $invoice->due_date?->isPast()) {
            return;
        }
        $tenant = PlatformTenant::forTeam($invoice->team_id);
        if (! self::hasActivePlan($tenant) || $tenant->past_due_since) {
            return;
        }
        $tenant->update(['past_due_since' => $invoice->due_date->copy()->endOfDay()]);
        audit_log('platform.invoice.overdue', $invoice, ['due_date' => $invoice->due_date->toDateString()], $invoice->team_id, 'system');
        TenantLifecycle::notify($tenant, __('Payment failed'), [
            __('Invoice :number is overdue. Pay it with Pix or boleto to avoid interruptions.', ['number' => $invoice->number]),
            __('Deployments are suspended after :days days and containers are stopped after :hard days.', [
                'days' => (int) config('platform.enforcement.suspend_after_days', 7),
                'hard' => (int) config('platform.enforcement.hard_suspend_after_days', 30),
            ]),
        ]);
    }

    /**
     * Scheduler entry point (every 15 minutes).
     */
    public static function runCycle(): void
    {
        if (! self::ready()) {
            return;
        }
        self::renewDue();
        self::markOverdue();
        self::reconcileOpen();
    }

    public static function renewDue(): int
    {
        $count = 0;
        PlatformTenant::query()
            ->where('billing_provider', self::PROVIDER)
            ->whereNotNull('plan')
            ->whereNotIn('status', [PlatformTenant::STATUS_PENDING, PlatformTenant::STATUS_CANCELLED])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->chunkById(100, function ($tenants) use (&$count) {
                foreach ($tenants as $tenant) {
                    try {
                        if (self::renew($tenant)) {
                            $count++;
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }

    public static function renew(PlatformTenant $tenant): ?PlatformInvoice
    {
        if ($tenant->cancel_at_period_end) {
            self::endSubscription($tenant);

            return null;
        }
        $start = CarbonImmutable::instance($tenant->current_period_end);
        $existing = PlatformInvoice::query()
            ->where('team_id', $tenant->team_id)
            ->where('type', PlatformInvoice::TYPE_SUBSCRIPTION)
            ->where('period_start', $start)
            ->first();
        if ($existing) {
            return null;
        }
        $plan = PlanCatalog::exists($tenant->pending_plan) ? $tenant->pending_plan : $tenant->plan;
        $interval = $tenant->pending_interval ?: ($tenant->billing_interval ?: PlanCatalog::INTERVAL_MONTHLY);
        $price = self::priceCents($plan, $interval);
        if (is_null($price)) {
            [$plan, $interval] = [$tenant->plan, $tenant->billing_interval ?: PlanCatalog::INTERVAL_MONTHLY];
            $price = self::priceCents($plan, $interval);
        }
        if (is_null($price)) {
            audit_log('platform.invoice.renewal_skipped', $tenant, ['reason' => 'plan without price', 'plan' => $plan], $tenant->team_id, 'system');

            return null;
        }
        $usageFrom = $tenant->current_period_start
            ?? ($interval === PlanCatalog::INTERVAL_YEARLY ? $start->subYearNoOverflow() : $start->subMonthNoOverflow());
        $usage = PricingEngine::calculate($tenant, $usageFrom, $start);
        $lines = [self::planLine($plan, $interval, $price)];
        foreach ((array) ($usage['lines'] ?? []) as $line) {
            if ((int) ($line['amount_cents'] ?? 0) > 0) {
                $lines[] = $line;
            }
        }
        $invoice = self::createInvoice($tenant, PlatformInvoice::TYPE_SUBSCRIPTION, $plan, $interval, $start, self::periodEnd($start, $interval), $lines);
        $invoice->forceFill(['metadata' => array_merge((array) $invoice->metadata, [
            'usage_from' => CarbonImmutable::instance($usageFrom)->toIso8601String(),
            'usage_to' => $start->toIso8601String(),
        ])])->save();
        try {
            self::issue($invoice);
        } catch (\Throwable $e) {
            report($e);
        }

        return $invoice;
    }

    public static function endSubscription(PlatformTenant $tenant): void
    {
        $tenant->update([
            'status' => PlatformTenant::STATUS_CANCELLED,
            'suspended_at' => now(),
            'suspension_reason' => TenantLifecycle::REASON_CANCELLED,
            'cancel_at_period_end' => false,
            'pending_plan' => null,
            'pending_interval' => null,
        ]);
        $tenant = $tenant->fresh();
        self::syncCoolifySubscription($tenant);
        audit_log('platform.subscription.ended', $tenant, [], $tenant->team_id, 'system');
        TenantLifecycle::notify($tenant, __('Subscription cancelled'), [
            __('Your subscription ended. Deployments are disabled and the containers will be stopped in :days days unless you subscribe again.', [
                'days' => (int) config('platform.enforcement.suspend_after_days', 7),
            ]),
        ]);
    }

    public static function markOverdue(): int
    {
        $count = 0;
        PlatformInvoice::query()
            ->where('provider', self::PROVIDER)
            ->where('status', PlatformInvoice::STATUS_OPEN)
            ->whereDate('due_date', '<', today())
            ->chunkById(200, function ($invoices) use (&$count) {
                foreach ($invoices as $invoice) {
                    if ($invoice->type === PlatformInvoice::TYPE_UPGRADE) {
                        self::cancelInvoice($invoice, 'system');

                        continue;
                    }
                    self::flagOverdue($invoice);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Missed webhooks and charges that failed to register are picked up here.
     */
    public static function reconcileOpen(): int
    {
        $count = 0;
        PlatformInvoice::query()
            ->where('provider', self::PROVIDER)
            ->where('status', PlatformInvoice::STATUS_OPEN)
            ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', now()->subMinutes(30)))
            ->orderBy('id')
            ->limit(300)
            ->get()
            ->each(function (PlatformInvoice $invoice) use (&$count) {
                if (self::isManualInvoice($invoice)) {
                    return;
                }
                if (blank($invoice->provider_reference)) {
                    if ((int) data_get($invoice->metadata, 'issue_attempts', 0) < self::MAX_ISSUE_ATTEMPTS) {
                        try {
                            self::issue($invoice);
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }

                    return;
                }
                PlatformInterSyncInvoiceJob::dispatch($invoice->id);
                $count++;
            });

        return $count;
    }

    /**
     * Webhook of the Inter charges: only the codes are used, the status is read from the API.
     *
     * @param  array<mixed>  $payload
     */
    public static function handleWebhook(array $payload): int
    {
        $items = array_is_list($payload) ? $payload : (array) ($payload['cobrancas'] ?? [$payload]);
        $count = 0;
        foreach ($items as $item) {
            $code = (string) data_get($item, 'codigoSolicitacao', '');
            $number = (string) data_get($item, 'seuNumero', '');
            $invoice = PlatformInvoice::query()->where('provider', self::PROVIDER)
                ->where(function ($query) use ($code, $number) {
                    $query->when($code !== '', fn ($query) => $query->orWhere('provider_reference', $code))
                        ->when($number !== '', fn ($query) => $query->orWhere('number', $number));
                })
                ->when($code === '' && $number === '', fn ($query) => $query->whereRaw('1 = 0'))
                ->first();
            if ($invoice) {
                PlatformInterSyncInvoiceJob::dispatch($invoice->id);
                $count++;
            }
        }
        PlatformSetting::put('inter.last_webhook_at', now()->toIso8601String());

        return $count;
    }

    /**
     * New charge for an unpaid invoice (expired boleto, changed payer details...).
     */
    public static function reissue(PlatformInvoice $invoice): PlatformInvoice
    {
        if ($invoice->status === PlatformInvoice::STATUS_PAID) {
            return $invoice;
        }
        if (filled($invoice->provider_reference) && ! in_array($invoice->provider_status, self::VOID_STATUSES, true)) {
            try {
                self::client()->cancelCharge($invoice->provider_reference);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $metadata = (array) $invoice->metadata;
        unset($metadata['void_reason'], $metadata['issue_attempts'], $metadata['issue_error'], $metadata['notified_at']);
        $invoice->forceFill([
            'status' => PlatformInvoice::STATUS_OPEN,
            'provider_reference' => null,
            'provider_status' => null,
            'payment_details' => null,
            'due_date' => now()->addDays(max(1, (int) config('platform.billing.due_days', 5)))->toDateString(),
            'metadata' => $metadata,
        ])->save();

        return self::issue($invoice->fresh());
    }

    public static function cancelInvoice(PlatformInvoice $invoice, string $actorType = 'user'): void
    {
        if ($invoice->status !== PlatformInvoice::STATUS_OPEN) {
            return;
        }
        if (filled($invoice->provider_reference)) {
            try {
                self::client()->cancelCharge($invoice->provider_reference);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $invoice->forceFill([
            'status' => PlatformInvoice::STATUS_VOID,
            'metadata' => array_merge((array) $invoice->metadata, ['void_reason' => 'superseded']),
        ])->save();
        audit_log('platform.invoice.cancelled', $invoice, [], $invoice->team_id, $actorType);
    }

    private static function voidOpenInvoices(PlatformTenant $tenant, string $type): void
    {
        PlatformInvoice::query()
            ->where('team_id', $tenant->team_id)
            ->where('provider', self::PROVIDER)
            ->where('type', $type)
            ->where('status', PlatformInvoice::STATUS_OPEN)
            ->get()
            ->each(fn (PlatformInvoice $invoice) => self::cancelInvoice($invoice, 'system'));
    }

    public static function registerWebhook(): string
    {
        $url = InterSettings::webhookUrl();
        self::client()->registerWebhook($url);
        PlatformSetting::put('inter.webhook_registered_at', now()->toIso8601String());
        PlatformSetting::put('inter.webhook_registered_url', $url);
        audit_log('platform.inter.webhook_registered', null, ['url' => preg_replace('#/[^/]+$#', '/***', $url)], 0);

        return $url;
    }

    public static function describe(PlatformInvoice $invoice): string
    {
        $plan = PlanCatalog::get($invoice->plan)['name'] ?? (string) $invoice->plan;

        return match ($invoice->type) {
            PlatformInvoice::TYPE_UPGRADE => __('Upgrade to :plan', ['plan' => $plan]),
            default => __('Plan :plan', ['plan' => $plan]).' ('.($invoice->billing_interval === PlanCatalog::INTERVAL_YEARLY ? __('yearly') : __('monthly')).')',
        };
    }

    public static function notifyIssued(PlatformInvoice $invoice): void
    {
        $metadata = (array) $invoice->metadata;
        if (filled($metadata['notified_at'] ?? null)) {
            return;
        }
        $invoice->forceFill(['metadata' => array_merge($metadata, ['notified_at' => now()->toIso8601String()])])->save();
        $tenant = PlatformTenant::forTeam($invoice->team_id);
        $lines = [
            __('Invoice :number of :amount is available, due on :date.', [
                'number' => $invoice->number,
                'amount' => $invoice->formattedAmount(),
                'date' => $invoice->due_date?->format('d/m/Y'),
            ]),
            self::describe($invoice),
        ];
        if ($pix = data_get($invoice->payment_details, 'pix_copia_e_cola')) {
            $lines[] = __('Pix (copy and paste):').' '.$pix;
        }
        if ($line = data_get($invoice->payment_details, 'linha_digitavel')) {
            $lines[] = __('Boleto:').' '.$line;
        }
        if ($instructions = data_get($invoice->payment_details, 'instructions')) {
            $lines[] = (string) $instructions;
        }
        TenantLifecycle::notify($tenant, __('Invoice available'), $lines);
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
