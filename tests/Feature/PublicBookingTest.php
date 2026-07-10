<?php

declare(strict_types=1);

use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the public booking page for an active event type', function () {
    $host = User::factory()->create(['username' => 'alice', 'timezone' => 'UTC']);
    $eventType = EventType::factory()->create([
        'user_id' => $host->id,
        'slug' => 'coffee-chat',
        'is_active' => true,
    ]);
    AvailabilityWindow::factory()->create(['user_id' => $host->id, 'day_of_week' => 1]);

    $this->get(route('booking.show', ['username' => 'alice', 'slug' => 'coffee-chat']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Booking/Show')
            ->has('eventType')
            ->has('slots')
        );
});

it('returns 404 for inactive event type', function () {
    $host = User::factory()->create(['username' => 'alice']);
    EventType::factory()->inactive()->create([
        'user_id' => $host->id,
        'slug' => 'hidden',
    ]);

    $this->get(route('booking.show', ['username' => 'alice', 'slug' => 'hidden']))
        ->assertNotFound();
});

it('returns 404 for unknown username', function () {
    $this->get(route('booking.show', ['username' => 'nobody', 'slug' => 'any']))
        ->assertNotFound();
});

it('returns 404 for unknown slug', function () {
    $host = User::factory()->create(['username' => 'alice']);
    EventType::factory()->create(['user_id' => $host->id, 'slug' => 'real-slug', 'is_active' => true]);

    $this->get(route('booking.show', ['username' => 'alice', 'slug' => 'fake-slug']))
        ->assertNotFound();
});

it('does not require authentication to view the booking page', function () {
    $host = User::factory()->create(['username' => 'alice']);
    EventType::factory()->create(['user_id' => $host->id, 'slug' => 'coffee-chat', 'is_active' => true]);

    $this->get(route('booking.show', ['username' => 'alice', 'slug' => 'coffee-chat']))
        ->assertOk();
});

it('falls back to UTC when the timezone query param is invalid', function () {
    $host = User::factory()->create(['username' => 'alice', 'timezone' => 'UTC']);
    EventType::factory()->create(['user_id' => $host->id, 'slug' => 'coffee-chat', 'is_active' => true]);
    AvailabilityWindow::factory()->create(['user_id' => $host->id, 'day_of_week' => 1]);

    $this->get(route('booking.show', ['username' => 'alice', 'slug' => 'coffee-chat', 'tz' => 'Not/AZone']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('guestTimezone', 'UTC'));
});

it('keeps a valid timezone query param', function () {
    $host = User::factory()->create(['username' => 'alice', 'timezone' => 'UTC']);
    EventType::factory()->create(['user_id' => $host->id, 'slug' => 'coffee-chat', 'is_active' => true]);

    $this->get(route('booking.show', ['username' => 'alice', 'slug' => 'coffee-chat', 'tz' => 'Asia/Kuala_Lumpur']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('guestTimezone', 'Asia/Kuala_Lumpur'));
});

it('falls back to today when the date query param is invalid', function () {
    $host = User::factory()->create(['username' => 'alice', 'timezone' => 'UTC']);
    EventType::factory()->create(['user_id' => $host->id, 'slug' => 'coffee-chat', 'is_active' => true]);

    $this->get(route('booking.show', ['username' => 'alice', 'slug' => 'coffee-chat', 'date' => 'not-a-date']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('selectedDate', now('UTC')->format('Y-m-d')));
});

it('passes the event type details to the page', function () {
    $host = User::factory()->create(['username' => 'alice', 'name' => 'Alice Smith']);
    EventType::factory()->create([
        'user_id' => $host->id,
        'slug' => 'coffee-chat',
        'name' => 'Coffee Chat',
        'duration_minutes' => 30,
        'is_active' => true,
    ]);

    $this->get(route('booking.show', ['username' => 'alice', 'slug' => 'coffee-chat']))
        ->assertInertia(fn ($page) => $page
            ->where('eventType.name', 'Coffee Chat')
            ->where('eventType.duration_minutes', 30)
        );
});

// ── location ──────────────────────────────────────────────────────────────────

it('exposes the event types location on the show page', function () {
    $host = User::factory()->create(['username' => 'alice', 'timezone' => 'UTC']);
    EventType::factory()->create([
        'user_id' => $host->id,
        'slug' => 'coffee-chat',
        'is_active' => true,
        'location' => 'Zoom link sent after booking',
    ]);

    $this->get(route('booking.show', ['username' => 'alice', 'slug' => 'coffee-chat']))
        ->assertInertia(fn ($page) => $page->where('eventType.location', 'Zoom link sent after booking'));
});

it('exposes the bookings location on the confirmation page', function () {
    $host = User::factory()->create(['username' => 'alice']);
    $eventType = EventType::factory()->create(['user_id' => $host->id, 'slug' => 'coffee-chat', 'is_active' => true]);
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'location' => '123 Main St, Suite 4',
    ]);

    $this->get(route('booking.confirmation', ['username' => 'alice', 'slug' => 'coffee-chat', 'booking' => $booking->id]))
        ->assertInertia(fn ($page) => $page
            ->component('Public/Booking/Confirmation')
            ->where('booking.location', '123 Main St, Suite 4')
        );
});
