<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The privacy choices of a user: the version of the privacy policy and terms of use accepted,
 * whether the user consents to promotions and news (LGPD, art. 8) and when the user declared to
 * be an adult (art. 14).
 */
class PrivacyPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'marketing' => 'boolean',
            'marketing_updated_at' => 'datetime',
            'policy_version' => 'integer',
            'policy_accepted_at' => 'datetime',
            'adult_confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
