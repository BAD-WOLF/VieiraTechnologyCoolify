<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Visus\Cuid2\Cuid2;

class PlatformWebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformWebhookDelivery $delivery) {
            $delivery->uuid ??= (string) new Cuid2;
        });
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(PlatformWebhook::class, 'platform_webhook_id');
    }
}
