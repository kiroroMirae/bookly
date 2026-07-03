<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\EventType;
use Carbon\CarbonImmutable;

class SlotGenerator
{
    public function forDate(EventType $eventType, CarbonImmutable $date, string $guestTimezone, ?int $ignoreBookingId = null): array
    {
        $host = $eventType->user;
        $hostTimezone = $host->timezone;

        // Parse as a calendar date in the host's timezone to avoid cross-midnight shifts
        $hostDate = CarbonImmutable::parse($date->format('Y-m-d'), $hostTimezone);
        $dayOfWeek = (int) $hostDate->format('w'); // 0=Sunday … 6=Saturday

        $windowEndDate = CarbonImmutable::now($hostTimezone)->startOfDay()->addDays($eventType->booking_window_days);

        if ($hostDate->startOfDay()->gt($windowEndDate)) {
            return [];
        }

        $overrides = $host->availabilityOverrides()
            ->whereDate('date', $hostDate->format('Y-m-d'))
            ->orderBy('start_time')
            ->get();

        if ($overrides->contains(fn ($override) => $override->isFullDayBlock())) {
            return [];
        }

        // Timed overrides replace the weekly schedule for this date
        $windows = $overrides->isNotEmpty()
            ? $overrides
            : $host->availabilityWindows()
                ->where('day_of_week', $dayOfWeek)
                ->orderBy('start_time')
                ->get();

        if ($windows->isEmpty()) {
            return [];
        }

        $dayStartUtc = $hostDate->startOfDay()->utc();
        $dayEndUtc = $hostDate->endOfDay()->utc();

        $existingBookings = $host->bookings()
            ->whereNot('status', BookingStatus::Cancelled->value)
            ->when($ignoreBookingId !== null, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->where('starts_at', '<', $dayEndUtc)
            ->where('ends_at', '>', $dayStartUtc)
            ->get(['starts_at', 'ends_at']);

        if ($eventType->max_bookings_per_day !== null) {
            $bookingsOfThisTypeToday = $eventType->bookings()
                ->whereNot('status', BookingStatus::Cancelled->value)
                ->when($ignoreBookingId !== null, fn ($query) => $query->whereKeyNot($ignoreBookingId))
                ->where('starts_at', '<', $dayEndUtc)
                ->where('ends_at', '>', $dayStartUtc)
                ->count();

            if ($bookingsOfThisTypeToday >= $eventType->max_bookings_per_day) {
                return [];
            }
        }

        $bufferBefore = $eventType->buffer_before_minutes;
        $bufferAfter = $eventType->buffer_after_minutes;

        $now = CarbonImmutable::now('UTC');
        $earliestBookable = $now->addMinutes($eventType->minimum_notice_minutes);
        $duration = $eventType->duration_minutes;
        $slots = [];

        foreach ($windows as $window) {
            $windowStart = CarbonImmutable::parse(
                $hostDate->format('Y-m-d').' '.$window->start_time,
                $hostTimezone
            );
            $windowEnd = CarbonImmutable::parse(
                $hostDate->format('Y-m-d').' '.$window->end_time,
                $hostTimezone
            );

            $slotStart = $windowStart;

            while (true) {
                $slotEnd = $slotStart->addMinutes($duration);

                if ($slotEnd->gt($windowEnd)) {
                    break;
                }

                $slotStartUtc = $slotStart->utc();
                $slotEndUtc = $slotEnd->utc();

                if ($slotStartUtc->gte($earliestBookable)) {
                    $isBooked = $existingBookings->contains(
                        fn ($b) => $b->starts_at->subMinutes($bufferBefore)->lt($slotEndUtc)
                            && $b->ends_at->addMinutes($bufferAfter)->gt($slotStartUtc)
                    );

                    if (! $isBooked) {
                        $slots[] = [
                            'starts_at' => $slotStartUtc->toIso8601String(),
                            'display' => $slotStart->setTimezone($guestTimezone)->format('g:i A'),
                        ];
                    }
                }

                $slotStart = $slotEnd;
            }
        }

        return $slots;
    }
}
