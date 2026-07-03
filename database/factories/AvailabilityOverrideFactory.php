<?php

namespace Database\Factories;

use App\Models\AvailabilityOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityOverride>
 */
class AvailabilityOverrideFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => fake()->dateTimeBetween('+1 day', '+30 days')->format('Y-m-d'),
            'start_time' => null,
            'end_time' => null,
        ];
    }

    public function withHours(string $start = '13:00:00', string $end = '15:00:00'): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }
}
