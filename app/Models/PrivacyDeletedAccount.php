<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Record of a deleted account: who asked, when it was done and what was kept. Holds the e-mail
 * only while the access records of the account must be kept (Marco Civil da Internet, art. 15)
 * and is then removed.
 */
class PrivacyDeletedAccount extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const BY_SELF = 'self';

    public const BY_OWNER = 'owner';

    public const BY_RETENTION = 'retention';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'completed_at' => 'datetime',
            'retain_until' => 'datetime',
        ];
    }

    public static function requestedByLabel(string $by): string
    {
        return match ($by) {
            self::BY_SELF => __('The user'),
            self::BY_OWNER => __('The owner'),
            default => __('Retention period'),
        };
    }
}
