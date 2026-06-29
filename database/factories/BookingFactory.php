<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        $host = User::factory()->create();
        $eventType = EventType::factory()->create(['user_id' => $host->id]);
        $startsAt = now()->addDays(fake()->numberBetween(1, 14))->setTime(10, 0, 0);

        return [
            'event_type_id' => $eventType->id,
            'host_user_id' => $host->id,
            'guest_name' => fake()->name(),
            'guest_email' => fake()->safeEmail(),
            'guest_timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($eventType->duration_minutes),
            'status' => BookingStatus::Confirmed,
            'cancellation_reason' => null,
            'host_notes' => null,
            'reminder_sent_at' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
            'cancellation_reason' => fake()->sentence(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Completed,
        ]);
    }

    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subDays(2)->setTime(10, 0, 0),
            'ends_at' => now()->subDays(2)->setTime(10, 30, 0),
        ]);
    }

    public function reminderSent(): static
    {
        return $this->state(fn (array $attributes) => [
            'reminder_sent_at' => now()->subHour(),
        ]);
    }
}
