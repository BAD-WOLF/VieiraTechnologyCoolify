<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AggregatedUsageHourly extends Model
{
    protected $table = 'aggregated_usage_hourly';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'samples' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
