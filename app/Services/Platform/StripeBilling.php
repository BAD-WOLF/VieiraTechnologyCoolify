<?php

namespace App\Services\Platform;

use App\Actions\Platform\ProvisionTenant;
use App\Models\PlatformInvoice;
use App\Models\PlatformTenant;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Stripe\StripeClient;

/**
 * Stripe integration of the platform plans (wiki 8: "Integração com gateways e webhooks").
 *
 * - Checkout for a plan (one recurring price per plan and interval)
 * - Subscription webhooks keep the tenant plan, billing period and payment state in sync
 * - Usage overage is computed by the pricing engine and attached to the renewal invoice
 *   ("invoice.created" of a subscription cycle), mirrored locally in platform_invoices
 */
class StripeBilling
{
    /**
     * Stripe event types handled by the platform (in addition to Coolify's own subscription bookkeeping).
     */
    public const EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.created',
        'invoice.finalized',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.voided',
        'invoice.marked_uncollectible',
        'charge.refunded',
    ];

    public static function client(): StripeClient
    {
        return new StripeClient(config('subscription.stripe_api_key'));
    }

    public static function checkoutUrl(Team $team, User $user, string $plan, string $interval): string
    {
        $priceId = PlanCatalog::priceIdFor($plan, $interval);
        if (! PlanCatalog::isSelfServe($plan, $interval) || blank($priceId)) {
            throw new \RuntimeException(__('This plan is not available for self-service checkout.'));
        }
        $payload = [
            'mode' => 'subscription',
            'client_reference_id' => $user->id.':'.$team->id,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'required',
            'tax_id_collection' => ['enabled' => true],
            'automatic_tax' => ['enabled' => (bool) config('platform.stripe_automatic_tax', false)],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => $user->id,
                    'team_id' => $team->id,
                    'platform_plan' => $plan,
                    'platform_interval' => $interval,
                ],
            ],
            'success_url' => route('subscription.show', ['success' => true]),
            'cancel_url' => route('subscription.index', ['cancelled' => true]),
        ];
        $trialDays = (int) config('platform.trial_days', 0);
        if ($trialDays > 0 && ! $team->subscription()->exists()) {
            $payload['subscription_data']['trial_period_days'] = $trialDays;
        }
        $customer = $team->subscription?->stripe_customer_id;
        if ($customer) {
            $payload['customer'] = $customer;
            $payload['customer_update'] = ['name' => 'auto', 'address' => 'auto'];
        } else {
            $payload['customer_email'] = $user->email;
        }

        return self::client()->checkout->sessions->create($payload)->url;
    }

    /**
     * Route a Stripe event to the platform handlers. Never throws (the webhook must be acknowledged).
     *
     * @param  array<string, mixed>  $data  event.data.object
     */
    public static function handle(string $type, array $data): void
    {
        if (! isPlatform() || ! in_array($type, self::EVENTS, true)) {
            return;
        }
        try {
            match ($type) {
                'customer.subscription.created', 'customer.subscription.updated' => self::syncSubscription($data),
                'customer.subscription.deleted' => self::subscriptionDeleted($data),
                'invoice.created' => self::invoiceCreated($data),
                'invoice.paid' => self::invoicePaid($data),
                'invoice.payment_failed' => self::invoicePaymentFailed($data),
                'invoice.finalized', 'invoice.voided', 'invoice.marked_uncollectible' => self::syncInvoiceStatus($data),
                'charge.refunded' => self::chargeRefunded($data),
            };
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    public static function tenantFor(array $subscription): ?PlatformTenant
    {
        $teamId = data_get($subscription, 'metadata.team_id');
        if (blank($teamId)) {
            $teamId = Subscription::query()->where('stripe_customer_id', data_get($subscription, 'customer'))->value('team_id');
        }
        if (blank($teamId) || ! Team::query()->whereKey($teamId)->exists() || (int) $teamId === 0) {
            return null;
        }

        return PlatformTenant::forTeam((int) $teamId);
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    public static function syncSubscription(array $subscription): ?PlatformTenant
    {
        $tenant = self::tenantFor($subscription);
        if (is_null($tenant)) {
            return null;
        }
        $priceId = data_get($subscription, 'items.data.0.price.id') ?? data_get($subscription, 'items.data.0.plan.id') ?? data_get($subscription, 'plan.id');
        $mapped = PlanCatalog::planForPriceId($priceId);
        $status = (string) data_get($subscription, 'status');
        $attributes = [
            'current_period_start' => self::timestamp(data_get($subscription, 'current_period_start') ?? data_get($subscription, 'items.data.0.current_period_start')),
            'current_period_end' => self::timestamp(data_get($subscription, 'current_period_end') ?? data_get($subscription, 'items.data.0.current_period_end')),
        ];
        if ($mapped) {
            [$attributes['plan'], $attributes['billing_interval']] = $mapped;
        }

        if (in_array($status, ['active', 'trialing'], true)) {
            $attributes['past_due_since'] = null;
            if (in_array($tenant->status, [PlatformTenant::STATUS_PENDING, PlatformTenant::STATUS_CANCELLED], true)) {
                $attributes['status'] = PlatformTenant::STATUS_ACTIVE;
                $attributes['suspended_at'] = null;
                $attributes['suspension_reason'] = null;
            }
        } elseif (in_array($status, ['past_due', 'unpaid'], true)) {
            $attributes['past_due_since'] = $tenant->past_due_since ?? now();
        } elseif ($status === 'paused') {
            $attributes['status'] = PlatformTenant::STATUS_SUSPENDED;
            $attributes['suspended_at'] = $tenant->suspended_at ?? now();
            $attributes['suspension_reason'] = TenantLifecycle::REASON_OVERDUE;
        } elseif (in_array($status, ['canceled', 'incomplete_expired'], true)) {
            $attributes['status'] = PlatformTenant::STATUS_CANCELLED;
            $attributes['suspended_at'] = $tenant->suspended_at ?? now();
            $attributes['suspension_reason'] = TenantLifecycle::REASON_CANCELLED;
        }
        $wasReactivation = in_array($tenant->status, [PlatformTenant::STATUS_SUSPENDED, PlatformTenant::STATUS_HARD_SUSPENDED], true)
            && in_array($status, ['active', 'trialing'], true)
            && in_array($tenant->suspension_reason, [TenantLifecycle::REASON_OVERDUE, TenantLifecycle::REASON_CANCELLED], true);
        $tenant->update($attributes);

        if ($wasReactivation) {
            TenantLifecycle::reactivate($tenant->fresh());
        }
        if ($tenant->fresh()->status === PlatformTenant::STATUS_ACTIVE && is_null($tenant->provisioned_at)) {
            ProvisionTenant::dispatch($tenant->team_id);
        }

        return $tenant->fresh();
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    public static function subscriptionDeleted(array $subscription): void
    {
        $tenant = self::tenantFor($subscription);
        if (is_null($tenant)) {
            return;
        }
        $tenant->update([
            'status' => PlatformTenant::STATUS_CANCELLED,
            'suspended_at' => now(),
            'suspension_reason' => TenantLifecycle::REASON_CANCELLED,
        ]);
        TenantLifecycle::notify($tenant, __('Subscription cancelled'), [
            __('Your subscription ended. Deployments are disabled and the containers will be stopped in :days days unless you subscribe again.', ['days' => (int) config('platform.enforcement.suspend_after_days', 7)]),
        ]);
    }

    /**
     * Renewal invoice created as draft: attach the usage overage of the period that just ended.
     *
     * @param  array<string, mixed>  $invoice
     */
    public static function invoiceCreated(array $invoice): void
    {
        if (data_get($invoice, 'billing_reason') !== 'subscription_cycle' || data_get($invoice, 'status') !== 'draft') {
            return;
        }
        $teamId = Subscription::query()->where('stripe_customer_id', data_get($invoice, 'customer'))->value('team_id');
        if (blank($teamId) || (int) $teamId === 0) {
            return;
        }
        $from = self::timestamp(data_get($invoice, 'period_start'));
        $to = self::timestamp(data_get($invoice, 'period_end'));
        if (is_null($from) || is_null($to) || $to->lte($from)) {
            return;
        }
        self::billUsage(PlatformTenant::forTeam((int) $teamId), $from, $to, (string) data_get($invoice, 'id'), (string) data_get($invoice, 'customer'));
    }

    /**
     * Compute the overage of a period, store it locally and push it to Stripe (idempotent).
     */
    public static function billUsage(PlatformTenant $tenant, Carbon $from, Carbon $to, ?string $stripeInvoiceId = null, ?string $customerId = null): PlatformInvoice
    {
        $calculation = PricingEngine::calculate($tenant, $from, $to);
        $invoice = PlatformInvoice::query()->firstOrCreate(
            ['team_id' => $tenant->team_id, 'type' => 'usage', 'period_start' => $from],
            [
                'period_end' => $to,
                'currency' => $calculation['currency'],
                'amount_cents' => $calculation['amount_cents'],
                'lines' => $calculation['lines'],
                'status' => $calculation['amount_cents'] > 0 ? PlatformInvoice::STATUS_OPEN : PlatformInvoice::STATUS_PAID,
                'metadata' => ['plan' => $tenant->plan],
            ],
        );
        $customerId ??= $tenant->team?->subscription?->stripe_customer_id;
        if ($invoice->amount_cents <= 0 || blank($customerId) || filled($invoice->stripe_invoice_item_ids)) {
            if ($stripeInvoiceId && blank($invoice->stripe_invoice_id)) {
                $invoice->update(['stripe_invoice_id' => $stripeInvoiceId]);
            }

            return $invoice;
        }
        $itemIds = [];
        foreach ($invoice->lines as $line) {
            if ((int) $line['amount_cents'] <= 0) {
                continue;
            }
            $payload = [
                'customer' => $customerId,
                'amount' => (int) $line['amount_cents'],
                'currency' => $invoice->currency,
                'description' => $line['description'],
                'metadata' => ['platform_invoice' => $invoice->uuid, 'code' => $line['code']],
                'period' => ['start' => $from->getTimestamp(), 'end' => $to->getTimestamp()],
            ];
            if ($stripeInvoiceId) {
                $payload['invoice'] = $stripeInvoiceId;
            }
            $item = self::client()->invoiceItems->create($payload, ['idempotency_key' => "platform-{$invoice->uuid}-{$line['code']}"]);
            $itemIds[] = $item->id;
        }
        $invoice->update(['stripe_invoice_item_ids' => $itemIds, 'stripe_invoice_id' => $stripeInvoiceId ?? $invoice->stripe_invoice_id]);
        audit_log('billing.usage_invoiced', $invoice, ['amount_cents' => $invoice->amount_cents, 'stripe_invoice' => $stripeInvoiceId], $tenant->team_id, 'system');

        return $invoice->fresh();
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public static function invoicePaid(array $invoice): void
    {
        self::syncInvoiceStatus($invoice);
        $tenant = self::tenantForCustomer((string) data_get($invoice, 'customer'));
        if (is_null($tenant)) {
            return;
        }
        $tenant->update(['past_due_since' => null]);
        if (in_array($tenant->status, [PlatformTenant::STATUS_SUSPENDED, PlatformTenant::STATUS_HARD_SUSPENDED], true)
            && $tenant->suspension_reason === TenantLifecycle::REASON_OVERDUE) {
            TenantLifecycle::reactivate($tenant->fresh());
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public static function invoicePaymentFailed(array $invoice): void
    {
        self::syncInvoiceStatus($invoice);
        $tenant = self::tenantForCustomer((string) data_get($invoice, 'customer'));
        if (is_null($tenant) || $tenant->past_due_since) {
            return;
        }
        $tenant->update(['past_due_since' => now()]);
        TenantLifecycle::notify($tenant, __('Payment failed'), [
            __('We could not charge your last invoice. Update your payment method to avoid interruptions.'),
            __('Deployments are suspended after :days days and containers are stopped after :hard days.', [
                'days' => (int) config('platform.enforcement.suspend_after_days', 7),
                'hard' => (int) config('platform.enforcement.hard_suspend_after_days', 30),
            ]),
        ]);
    }

    /**
     * Mirror the Stripe invoice status on the usage invoices attached to it.
     *
     * @param  array<string, mixed>  $invoice
     */
    public static function syncInvoiceStatus(array $invoice): void
    {
        $status = match ((string) data_get($invoice, 'status')) {
            'paid' => PlatformInvoice::STATUS_PAID,
            'void' => PlatformInvoice::STATUS_VOID,
            'uncollectible' => PlatformInvoice::STATUS_UNCOLLECTIBLE,
            'open' => PlatformInvoice::STATUS_OPEN,
            default => null,
        };
        if (is_null($status)) {
            return;
        }
        PlatformInvoice::query()->where('stripe_invoice_id', data_get($invoice, 'id'))->get()->each(function (PlatformInvoice $local) use ($status, $invoice) {
            $local->update([
                'status' => $status,
                'paid_at' => $status === PlatformInvoice::STATUS_PAID ? ($local->paid_at ?? now()) : $local->paid_at,
                'stripe_hosted_invoice_url' => data_get($invoice, 'hosted_invoice_url') ?? $local->stripe_hosted_invoice_url,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    public static function chargeRefunded(array $charge): void
    {
        $invoiceId = data_get($charge, 'invoice');
        if (blank($invoiceId)) {
            return;
        }
        $fullyRefunded = (bool) data_get($charge, 'refunded', false);
        PlatformInvoice::query()->where('stripe_invoice_id', $invoiceId)->get()->each(function (PlatformInvoice $local) use ($fullyRefunded, $charge) {
            $metadata = (array) $local->metadata;
            $metadata['amount_refunded'] = (int) data_get($charge, 'amount_refunded', 0);
            $local->update(['status' => $fullyRefunded ? PlatformInvoice::STATUS_REFUNDED : $local->status, 'metadata' => $metadata]);
            audit_log('billing.refunded', $local, ['amount_refunded' => $metadata['amount_refunded']], $local->team_id, 'system');
        });
    }

    public static function tenantForCustomer(string $customerId): ?PlatformTenant
    {
        $teamId = Subscription::query()->where('stripe_customer_id', $customerId)->value('team_id');
        if (blank($teamId) || (int) $teamId === 0) {
            return null;
        }

        return PlatformTenant::forTeam((int) $teamId);
    }

    private static function timestamp(mixed $value): ?Carbon
    {
        if (blank($value) || ! is_numeric($value)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $value);
    }
}
