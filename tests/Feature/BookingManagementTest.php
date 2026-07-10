<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── auth ──────────────────────────────────────────────────────────────────────

it('guest is redirected from bookings index', function () {
    $this->get(route('bookings.index'))->assertRedirect(route('login'));
});

it('guest cannot cancel a booking', function () {
    $booking = Booking::factory()->create();

    $this->patch(route('bookings.cancel', $booking))->assertRedirect(route('login'));
});

// ── index ─────────────────────────────────────────────────────────────────────

it('host sees their bookings page', function () {
    $host = User::factory()->create();

    $this->actingAs($host)
        ->get(route('bookings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Bookings/Index')
            ->has('upcoming')
            ->has('past')
        );
});

it('host sees their own bookings in the list', function () {
    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id]);

    Booking::factory()->create([
        'host_user_id' => $host->id,
        'event_type_id' => $eventType->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->get(route('bookings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Bookings/Index')
            ->has('upcoming', 1)
            ->has('past.data', 0)
        );
});

it('host does not see other hosts bookings', function () {
    $host = User::factory()->create();
    $other = User::factory()->create();

    Booking::factory()->create([
        'host_user_id' => $other->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
    ]);

    $this->actingAs($host)
        ->get(route('bookings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Bookings/Index')
            ->has('upcoming', 0)
            ->has('past.data', 0)
        );
});

// ── cancel ────────────────────────────────────────────────────────────────────

it('host can cancel their own booking', function () {
    $host = User::factory()->create();
    $booking = Booking::factory()->create([
        'host_user_id' => $host->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.cancel', $booking))
        ->assertRedirect(route('bookings.index'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('host cannot cancel another hosts booking', function () {
    $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

    $this->actingAs(User::factory()->create())
        ->patch(route('bookings.cancel', $booking))
        ->assertForbidden();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});
