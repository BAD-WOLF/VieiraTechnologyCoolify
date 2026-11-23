<?php

namespace Database\Factories;

use App\Models\PrivacyRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivacyRequest>
 */
class PrivacyRequestFactory extends Factory
{
    protected $model = PrivacyRequest::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'type' => 'access',
            'message' => fake()->sentence(),
            'status' => PrivacyRequest::STATUS_OPEN,
        ];
    }

    public function answered(): static
    {
        return $this->state(fn () => ['status' => PrivacyRequest::STATUS_ANSWERED, 'answer' => 'Done.', 'answered_at' => now()]);
    }
}
