<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── helpers ───────────────────────────────────────────────────────────────────

function bookingHost(): User
{
    return User::factory()->create(['username' => 'alice', 'timezone' => 'UTC']);
}

function bookingEventType(User $host): EventType
{
    return EventType::factory()->create([
        'user_id' => $host->id,
        'slug' => 'coffee-chat',
        'duration_minutes' => 30,
        'is_active' => true,
    ]);
}

function mondayWindow(User $host): void
{
    AvailabilityWindow::factory()->create([
        'user_id' => $host->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '11:00',
    ]);
}

// ── validation ────────────────────────────────────────────────────────────────

it('validates required guest fields', function () {
    $host = bookingHost();
    bookingEventType($host);

    $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [])
        ->assertSessionHasErrors(['guest_name', 'guest_email', 'guest_timezone', 'starts_at']);
});

it('validates guest_email must be a valid email', function () {
    Carbon::setTestNow('2025-01-05 00:00:00');
    $host = bookingHost();
    bookingEventType($host);
    mondayWindow($host);

    $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [
        'guest_name' => 'Bob',
        'guest_email' => 'not-an-email',
        'guest_timezone' => 'UTC',
        'starts_at' => '2025-01-06 09:00:00',
    ])->assertSessionHasErrors('guest_email');
})->afterEach(fn () => Carbon::setTestNow());

it('validates starts_at must be in the future', function () {
    $host = bookingHost();
    bookingEventType($host);

    $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [
        'guest_name' => 'Bob',
        'guest_email' => 'bob@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2020-01-01 09:00:00',
    ])->assertSessionHasErrors('starts_at');
});

// ── happy path ────────────────────────────────────────────────────────────────

it('guest can book an available slot', function () {
    Carbon::setTestNow('2025-01-05 00:00:00');
    $host = bookingHost();
    bookingEventType($host);
    mondayWindow($host);

    $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [
        'guest_name' => 'Bob Smith',
        'guest_email' => 'bob@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2025-01-06 09:00:00',
    ])->assertRedirect();

    $booking = Booking::sole();
    expect($booking->guest_name)->toBe('Bob Smith')
        ->and($booking->host_user_id)->toBe($host->id)
        ->and($booking->status->value)->toBe('confirmed')
        ->and($booking->ends_at->format('H:i'))->toBe('09:30');
})->afterEach(fn () => Carbon::setTestNow());

it('booking redirects to the confirmation page', function () {
    Carbon::setTestNow('2025-01-05 00:00:00');
    $host = bookingHost();
    bookingEventType($host);
    mondayWindow($host);

    $response = $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [
        'guest_name' => 'Bob Smith',
        'guest_email' => 'bob@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2025-01-06 09:00:00',
    ]);

    $booking = Booking::sole();
    $response->assertRedirect(
        route('booking.confirmation', ['username' => 'alice', 'slug' => 'coffee-chat', 'booking' => $booking->id])
    );
})->afterEach(fn () => Carbon::setTestNow());

// ── conflict guard ────────────────────────────────────────────────────────────

it('rejects booking when the slot is already taken', function () {
    Carbon::setTestNow('2025-01-05 00:00:00');
    $host = bookingHost();
    $eventType = bookingEventType($host);
    mondayWindow($host);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Confirmed,
    ]);

    $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [
        'guest_name' => 'Eve',
        'guest_email' => 'eve@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2025-01-06 09:00:00',
    ])->assertStatus(422);

    expect(Booking::count())->toBe(1);
})->afterEach(fn () => Carbon::setTestNow());

it('allows booking a slot freed by a cancellation', function () {
    Carbon::setTestNow('2025-01-05 00:00:00');
    $host = bookingHost();
    $eventType = bookingEventType($host);
    mondayWindow($host);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Cancelled,
    ]);

    $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [
        'guest_name' => 'Bob',
        'guest_email' => 'bob@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2025-01-06 09:00:00',
    ])->assertRedirect();

    expect(Booking::where('status', BookingStatus::Confirmed->value)->count())->toBe(1);
})->afterEach(fn () => Carbon::setTestNow());

// ── location snapshot ────────────────────────────────────────────────────────

it('snapshots the event types location onto the booking at creation time', function () {
    Carbon::setTestNow('2025-01-05 00:00:00');
    $host = bookingHost();
    $eventType = bookingEventType($host);
    $eventType->update(['location' => 'Zoom link sent after booking']);
    mondayWindow($host);

    $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [
        'guest_name' => 'Bob Smith',
        'guest_email' => 'bob@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2025-01-06 09:00:00',
    ])->assertRedirect();

    expect(Booking::sole()->location)->toBe('Zoom link sent after booking');
})->afterEach(fn () => Carbon::setTestNow());

it('keeps the bookings snapshotted location unchanged after the event type location later changes', function () {
    Carbon::setTestNow('2025-01-05 00:00:00');
    $host = bookingHost();
    $eventType = bookingEventType($host);
    $eventType->update(['location' => 'Original location']);
    mondayWindow($host);

    $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [
        'guest_name' => 'Bob Smith',
        'guest_email' => 'bob@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2025-01-06 09:00:00',
    ])->assertRedirect();

    $booking = Booking::sole();

    $eventType->update(['location' => 'Changed location']);

    expect($booking->fresh()->location)->toBe('Original location');
})->afterEach(fn () => Carbon::setTestNow());

it('returns 404 when booking an inactive event type', function () {
    $host = bookingHost();
    EventType::factory()->inactive()->create(['user_id' => $host->id, 'slug' => 'coffee-chat']);

    $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [
        'guest_name' => 'Bob',
        'guest_email' => 'bob@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2030-01-06 09:00:00',
    ])->assertNotFound();
});
