<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;

// ── factory smoke ─────────────────────────────────────────────────────────────

it('creates a user with username and timezone', function () {
    $user = User::factory()->create([
        'username' => 'johndoe',
        'timezone' => 'America/New_York',
    ]);

    expect($user->fresh()->username)->toBe('johndoe')
        ->and($user->fresh()->timezone)->toBe('America/New_York');
});

it('creates an event type belonging to a user', function () {
    $user = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $user->id]);

    expect($eventType->user->id)->toBe($user->id);
});

it('creates an availability window belonging to a user', function () {
    $user = User::factory()->create();
    $window = AvailabilityWindow::factory()->create([
        'user_id' => $user->id,
        'day_of_week' => 1,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    expect($window->user->id)->toBe($user->id)
        ->and($window->day_of_week)->toBe(1)
        ->and($window->start_time)->toBe('09:00:00');
});

it('creates a confirmed booking with correct relationships', function () {
    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id, 'duration_minutes' => 30]);

    $booking = Booking::factory()->create([
        'host_user_id' => $host->id,
        'event_type_id' => $eventType->id,
    ]);

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->host->id)->toBe($host->id)
        ->and($booking->eventType->id)->toBe($eventType->id);
});

// ── model relationships ───────────────────────────────────────────────────────

it('user hasMany eventTypes', function () {
    $user = User::factory()->create();
    EventType::factory()->count(2)->create(['user_id' => $user->id]);

    expect($user->eventTypes)->toHaveCount(2);
});

it('user hasMany availabilityWindows', function () {
    $user = User::factory()->create();
    AvailabilityWindow::factory()->count(3)->create(['user_id' => $user->id]);

    expect($user->availabilityWindows)->toHaveCount(3);
});

it('user hasMany bookings via host_user_id', function () {
    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id]);
    Booking::factory()->count(2)->create(['host_user_id' => $host->id, 'event_type_id' => $eventType->id]);

    expect($host->bookings)->toHaveCount(2);
});

// ── factory states ────────────────────────────────────────────────────────────

it('booking cancelled() state sets correct status', function () {
    $booking = Booking::factory()->cancelled()->create();

    expect($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancellation_reason)->not->toBeNull();
});

it('booking past() state has starts_at in the past', function () {
    $booking = Booking::factory()->past()->create();

    expect($booking->starts_at->isPast())->toBeTrue();
});

it('booking reminderSent() state has reminder_sent_at set', function () {
    $booking = Booking::factory()->reminderSent()->create();

    expect($booking->reminder_sent_at)->not->toBeNull();
});

it('event type is_active cast to boolean', function () {
    $eventType = EventType::factory()->create(['is_active' => true]);

    expect($eventType->is_active)->toBeTrue();
});

it('booking status casts to BookingStatus enum', function () {
    $booking = Booking::factory()->create();

    expect($booking->status)->toBeInstanceOf(BookingStatus::class);
});
