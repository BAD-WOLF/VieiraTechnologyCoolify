<?php

namespace Database\Factories;

use App\Models\PlatformNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformNotification>
 */
class PlatformNotificationFactory extends Factory
{
    protected $model = PlatformNotification::class;

    public function definition(): array
    {
        return [
            'kind' => PlatformNotification::KIND_UPDATE,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'audience' => PlatformNotification::AUDIENCE_ALL,
            'published_at' => now()->subMinute(),
        ];
    }

    public function forTeam(int $teamId, string $kind = PlatformNotification::KIND_ACCOUNT): static
    {
        return $this->state(['audience' => PlatformNotification::AUDIENCE_TEAM, 'team_id' => $teamId, 'kind' => $kind]);
    }
}
