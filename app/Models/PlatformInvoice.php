<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PlatformInvoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_UNCOLLECTIBLE = 'uncollectible';

    public const TYPE_SUBSCRIPTION = 'subscription';

    public const TYPE_UPGRADE = 'upgrade';

    public const TYPE_USAGE = 'usage';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'stripe_invoice_item_ids' => 'array',
            'metadata' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
            'amount_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'payment_details' => 'array',
            'due_date' => 'date',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformInvoice $invoice) {
            if (blank($invoice->uuid)) {
                $invoice->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Invoices with this uuid. The column is a native uuid: a value that is not one (typed in the
     * browser, for example) finds nothing instead of failing the query.
     *
     * @param  Builder<PlatformInvoice>  $query
     */
    public function scopeWithUuid(Builder $query, mixed $uuid): void
    {
        if (is_string($uuid) && Str::isUuid($uuid)) {
            $query->where('uuid', $uuid);
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function formattedAmount(): string
    {
        return formatPlatformMoney($this->amount_cents, $this->currency);
    }
}
