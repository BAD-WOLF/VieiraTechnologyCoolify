<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Proof of an acceptance or consent given or withdrawn (LGPD, art. 8, § 2º: the controller proves
 * it). Kept after the account is deleted, with the e-mail of the moment.
 */
class PrivacyConsent extends Model
{
    public const PURPOSE_POLICY = 'policy';

    public const PURPOSE_MARKETING = 'marketing';

    public const PURPOSE_ADULT = 'adult';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'policy_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public static function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            self::PURPOSE_POLICY => __('Privacy policy and terms of use accepted'),
            self::PURPOSE_MARKETING => __('Promotions and news'),
            self::PURPOSE_ADULT => __('Declared to be 18 or older'),
            default => $purpose,
        };
    }
}
