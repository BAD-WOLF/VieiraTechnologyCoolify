<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A security incident with personal data (LGPD, art. 48). The register is kept for at least five
 * years, communicated or not (Resolução CD/ANPD nº 15/2024, art. 10).
 */
class PrivacyIncident extends Model
{
    use HasFactory;

    /**
     * Business days to communicate the ANPD and the data subjects, from the moment the controller
     * knew the incident affected personal data (Resolução CD/ANPD nº 15/2024, arts. 6º and 9º).
     */
    public const COMMUNICATION_BUSINESS_DAYS = 3;

    public const AUDIENCES = ['all', 'customers', 'operators'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'known_at' => 'datetime',
            'occurred_at' => 'datetime',
            'relevant_risk' => 'boolean',
            'affected_count' => 'integer',
            'anpd_communicated_at' => 'datetime',
            'titulares_communicated_at' => 'datetime',
            'titulares_communicated_count' => 'integer',
        ];
    }

    /**
     * End of the third business day after the incident became known (Resolução CD/ANPD nº 15/2024,
     * art. 6º), counted in the calendar of the installation: in UTC an incident learned on a
     * Thursday night would count from Friday. Weekends are skipped and holidays count as business
     * days, so the deadline shown is never later than the legal one.
     */
    public function communicationDeadline(?string $timezone = null): CarbonInterface
    {
        $timezone ??= (string) (instanceSettings()->instance_timezone ?: config('app.timezone', 'UTC'));
        $deadline = $this->known_at->copy()->setTimezone($timezone);
        $days = 0;
        while ($days < self::COMMUNICATION_BUSINESS_DAYS) {
            $deadline = $deadline->addDay();
            if (! $deadline->isWeekend()) {
                $days++;
            }
        }

        return $deadline->endOfDay();
    }

    /**
     * A relevant incident not yet communicated to the ANPD or to the data subjects.
     */
    public function pendingCommunication(): bool
    {
        return $this->relevant_risk && (is_null($this->anpd_communicated_at) || is_null($this->titulares_communicated_at));
    }
}
