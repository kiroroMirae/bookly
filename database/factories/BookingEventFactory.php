<?php

namespace Database\Factories;

use App\Enums\BookingActor;
use App\Enums\BookingEventKind;
use App\Models\Booking;
use App\Models\BookingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingEvent>
 */
class BookingEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'actor' => BookingActor::Host,
            'event' => BookingEventKind::Cancelled,
            'metadata' => null,
        ];
    }
}
