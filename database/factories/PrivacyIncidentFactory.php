<?php

namespace Database\Factories;

use App\Models\PrivacyIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivacyIncident>
 */
class PrivacyIncidentFactory extends Factory
{
    protected $model = PrivacyIncident::class;

    public function definition(): array
    {
        return [
            'title' => 'Unauthorized access to a backup',
            'known_at' => now(),
            'description' => fake()->paragraph(),
            'data_categories' => 'Name and e-mail',
            'affected_count' => 10,
            'relevant_risk' => true,
        ];
    }
}
