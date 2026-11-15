<?php

namespace App\Models;

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

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function formattedAmount(): string
    {
        return formatPlatformMoney($this->amount_cents, $this->currency);
    }
}
