<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw metering sample (write-once). Metrics:
 * - cpu_seconds: CPU time consumed during the sampling interval
 * - memory_byte_seconds: memory usage integrated over the sampling interval
 * - egress_bytes: bytes transmitted by the tenant containers during the interval
 * - storage_bytes: storage used at sampling time (gauge)
 */
class UsageEvent extends Model
{
    public const UPDATED_AT = null;

    public const METRIC_CPU_SECONDS = 'cpu_seconds';

    public const METRIC_MEMORY_BYTE_SECONDS = 'memory_byte_seconds';

    public const METRIC_EGRESS_BYTES = 'egress_bytes';

    public const METRIC_STORAGE_BYTES = 'storage_bytes';

    public const METRICS = [
        self::METRIC_CPU_SECONDS,
        self::METRIC_MEMORY_BYTE_SECONDS,
        self::METRIC_EGRESS_BYTES,
        self::METRIC_STORAGE_BYTES,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'recorded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            return false;
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
