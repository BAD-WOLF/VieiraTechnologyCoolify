<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CPU/memory/containers reserved by a running tenant resource on a node (one row per resource, server and preview).
 */
class PlatformAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cpus' => 'float',
            'memory_mb' => 'integer',
            'containers' => 'integer',
            'pull_request_id' => 'integer',
        ];
    }
}
