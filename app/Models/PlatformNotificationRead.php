<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user read a notification of the bell.
 */
class PlatformNotificationRead extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'platform_notification_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(PlatformNotification::class, 'platform_notification_id');
    }
}
