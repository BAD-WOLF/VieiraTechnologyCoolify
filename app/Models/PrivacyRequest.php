<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A request of a data subject (LGPD, arts. 18 to 20), answered by the controller within 15 days.
 */
class PrivacyRequest extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_ANSWERED = 'answered';

    /**
     * Types of request, each a right of art. 18 (or 20).
     */
    public const TYPES = ['confirmation', 'access', 'correction', 'anonymization', 'portability', 'deletion', 'sharing', 'consent', 'opposition', 'review', 'other'];

    public const ANSWER_DAYS = 15;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (PrivacyRequest $request) {
            $request->uuid ??= (string) Str::uuid();
            $request->due_at ??= now()->addDays(self::ANSWER_DAYS);
        });
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at->isPast();
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'confirmation' => __('Confirm that my data is processed'),
            'access' => __('Access to my data'),
            'correction' => __('Correct incomplete, inaccurate or outdated data'),
            'anonymization' => __('Anonymize, block or delete unnecessary or excessive data'),
            'portability' => __('Portability of my data to another provider'),
            'deletion' => __('Delete my data'),
            'sharing' => __('With whom my data is shared'),
            'consent' => __('Withdraw a consent'),
            'opposition' => __('Oppose a processing that does not follow the law'),
            'review' => __('Review of a decision taken automatically'),
            default => __('Other request or complaint'),
        };
    }

    /**
     * The article of the LGPD behind each type.
     */
    public static function typeArticle(string $type): string
    {
        return match ($type) {
            'confirmation' => 'art. 18, I',
            'access' => 'art. 18, II',
            'correction' => 'art. 18, III',
            'anonymization' => 'art. 18, IV',
            'portability' => 'art. 18, V',
            'deletion' => 'art. 18, VI',
            'sharing' => 'art. 18, VII',
            'consent' => 'art. 18, IX',
            'opposition' => 'art. 18, § 2º',
            'review' => 'art. 20',
            default => 'art. 41, § 2º, I',
        };
    }
}
