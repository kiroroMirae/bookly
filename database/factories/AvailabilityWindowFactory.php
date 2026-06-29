<?php

namespace Database\Factories;

use App\Models\AvailabilityWindow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityWindow>
 */
class AvailabilityWindowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ];
    }
}
