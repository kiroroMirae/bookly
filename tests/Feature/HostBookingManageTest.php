<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Notifications\GuestBookingRescheduled;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2025-01-05 00:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @return array{0: User, 1: EventType} */
function hostManageSetup(): array
{
    $host = User::factory()->create(['username' => 'alice', 'timezone' => 'UTC']);
    $eventType = EventType::factory()->create([
        'user_id' => $host->id,
        'slug' => 'coffee-chat',
        'duration_minutes' => 30,
        'is_active' => true,
    ]);
    AvailabilityWindow::factory()->create([
        'user_id' => $host->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '11:00',
    ]);

    return [$host, $eventType];
}

// ── status transitions ───────────────────────────────────────────────────────

it('host can mark a past confirmed booking as completed', function () {
    [$host, $eventType] = hostManageSetup();
    $booking = Booking::factory()->past()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.update', $booking), ['status' => 'completed'])
        ->assertRedirect(route('bookings.index'));

    expect($booking->refresh()->status)->toBe(BookingStatus::Completed);
});

it('host can mark a past confirmed booking as no_show', function () {
    [$host, $eventType] = hostManageSetup();
    $booking = Booking::factory()->past()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.update', $booking), ['status' => 'no_show'])
        ->assertRedirect(route('bookings.index'));

    expect($booking->refresh()->status)->toBe(BookingStatus::NoShow);
});

it('cannot mark a future booking as completed', function () {
    [$host, $eventType] = hostManageSetup();
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.update', $booking), ['status' => 'completed'])
        ->assertStatus(422);

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('cannot mark a cancelled booking as completed', function () {
    [$host, $eventType] = hostManageSetup();
    $booking = Booking::factory()->past()->cancelled()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.update', $booking), ['status' => 'completed'])
        ->assertStatus(422);
});

it('rejects an invalid status value', function () {
    [$host, $eventType] = hostManageSetup();
    $booking = Booking::factory()->past()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.update', $booking), ['status' => 'bogus'])
        ->assertSessionHasErrors('status');
});

it('another user cannot update someone else\'s booking', function () {
    [$host, $eventType] = hostManageSetup();
    $other = User::factory()->create();
    $booking = Booking::factory()->past()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
    ]);

    $this->actingAs($other)
        ->patch(route('bookings.update', $booking), ['status' => 'completed'])
        ->assertForbidden();
});

// ── host notes ────────────────────────────────────────────────────────────────

it('host can set private notes on a booking', function () {
    [$host, $eventType] = hostManageSetup();
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.update', $booking), ['host_notes' => 'Wants to discuss pricing'])
        ->assertRedirect(route('bookings.index'));

    expect($booking->refresh()->host_notes)->toBe('Wants to discuss pricing');
});

// ── host reschedule ───────────────────────────────────────────────────────────

it('host can reschedule a confirmed booking to an open slot', function () {
    Notification::fake();
    [$host, $eventType] = hostManageSetup();
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'guest_email' => 'bob@example.com',
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Confirmed,
        'reminder_sent_at' => now()->subHour(),
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.reschedule', $booking), ['starts_at' => '2025-01-06 10:00:00'])
        ->assertRedirect(route('bookings.index'));

    $booking->refresh();
    expect($booking->starts_at->format('Y-m-d H:i'))->toBe('2025-01-06 10:00')
        ->and($booking->reminder_sent_at)->toBeNull()
        ->and($booking->ics_sequence)->toBe(1);

    Notification::assertSentOnDemand(GuestBookingRescheduled::class);
});

it('rejects host reschedule to a taken slot', function () {
    [$host, $eventType] = hostManageSetup();
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Confirmed,
    ]);
    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 10:00:00',
        'ends_at' => '2025-01-06 10:30:00',
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.reschedule', $booking), ['starts_at' => '2025-01-06 10:00:00'])
        ->assertStatus(422);

    expect($booking->refresh()->starts_at->format('H:i'))->toBe('09:00')
        ->and($booking->ics_sequence)->toBe(0);
});

it('another user cannot reschedule someone else\'s booking', function () {
    [$host, $eventType] = hostManageSetup();
    $other = User::factory()->create();
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($other)
        ->patch(route('bookings.reschedule', $booking), ['starts_at' => '2025-01-06 10:00:00'])
        ->assertForbidden();
});
