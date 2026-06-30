<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\GuestBookingReminder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('sends reminder for a booking 24 hours away', function () {
    Notification::fake();
    Carbon::setTestNow('2025-01-05 09:00:00');

    $booking = Booking::factory()->create([
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Confirmed,
        'reminder_sent_at' => null,
        'guest_email' => 'bob@example.com',
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertSentOnDemand(
        GuestBookingReminder::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'bob@example.com'
    );
    expect($booking->fresh()->reminder_sent_at)->not->toBeNull();
})->afterEach(fn () => Carbon::setTestNow());

it('does not resend a reminder that was already sent', function () {
    Notification::fake();
    Carbon::setTestNow('2025-01-05 09:00:00');

    Booking::factory()->create([
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Confirmed,
        'reminder_sent_at' => '2025-01-04 08:00:00',
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
})->afterEach(fn () => Carbon::setTestNow());

it('skips cancelled bookings', function () {
    Notification::fake();
    Carbon::setTestNow('2025-01-05 09:00:00');

    Booking::factory()->create([
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Cancelled,
        'reminder_sent_at' => null,
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
})->afterEach(fn () => Carbon::setTestNow());

it('skips bookings outside the 24-hour window', function () {
    Notification::fake();
    Carbon::setTestNow('2025-01-05 09:00:00');

    Booking::factory()->create([
        'starts_at' => '2025-01-07 09:00:00',
        'ends_at' => '2025-01-07 09:30:00',
        'status' => BookingStatus::Confirmed,
        'reminder_sent_at' => null,
    ]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
})->afterEach(fn () => Carbon::setTestNow());
