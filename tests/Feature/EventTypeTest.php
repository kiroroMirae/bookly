<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── index ─────────────────────────────────────────────────────────────────────

it('guest is redirected from event types index', function () {
    $this->get(route('event-types.index'))->assertRedirect(route('login'));
});

it('user sees only their own event types on index', function () {
    $user = User::factory()->create();
    EventType::factory()->count(2)->create(['user_id' => $user->id]);
    EventType::factory()->create(); // another user's

    $this->actingAs($user)
        ->get(route('event-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('EventTypes/Index')
            ->has('eventTypes', 2)
        );
});

// ── create ────────────────────────────────────────────────────────────────────

it('user can view the create form', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('event-types.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('EventTypes/Create'));
});

it('guest cannot access the create form', function () {
    $this->get(route('event-types.create'))->assertRedirect(route('login'));
});

// ── store ─────────────────────────────────────────────────────────────────────

it('user can store a new event type', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('event-types.store'), [
            'name' => 'Coffee Chat',
            'duration_minutes' => 30,
            'color' => '#3B82F6',
            'is_active' => true,
        ])
        ->assertRedirect(route('event-types.index'));

    $eventType = EventType::where('user_id', $user->id)->sole();

    expect($eventType->name)->toBe('Coffee Chat')
        ->and($eventType->slug)->toBe('coffee-chat')
        ->and($eventType->duration_minutes)->toBe(30);
});

it('slug is made unique when a collision exists for the same user', function () {
    $user = User::factory()->create();
    EventType::factory()->create([
        'user_id' => $user->id,
        'name' => 'My Meeting',
        'slug' => 'my-meeting',
    ]);

    $this->actingAs($user)
        ->post(route('event-types.store'), [
            'name' => 'My Meeting',
            'duration_minutes' => 30,
        ])
        ->assertRedirect(route('event-types.index'));

    expect(
        EventType::where('user_id', $user->id)->where('slug', 'my-meeting-2')->exists()
    )->toBeTrue();
});

it('store validates required fields', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('event-types.store'), [])
        ->assertSessionHasErrors(['name', 'duration_minutes']);
});

it('user can store booking policy fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('event-types.store'), [
            'name' => 'Deep Work',
            'duration_minutes' => 60,
            'buffer_before_minutes' => 10,
            'buffer_after_minutes' => 15,
            'minimum_notice_minutes' => 120,
            'booking_window_days' => 14,
            'max_bookings_per_day' => 3,
        ])
        ->assertRedirect(route('event-types.index'));

    $eventType = EventType::where('user_id', $user->id)->sole();

    expect($eventType->buffer_before_minutes)->toBe(10)
        ->and($eventType->buffer_after_minutes)->toBe(15)
        ->and($eventType->minimum_notice_minutes)->toBe(120)
        ->and($eventType->booking_window_days)->toBe(14)
        ->and($eventType->max_bookings_per_day)->toBe(3);
});

it('stores max_bookings_per_day as null when submitted as an empty string', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('event-types.store'), [
            'name' => 'No Limit',
            'duration_minutes' => 30,
            'max_bookings_per_day' => '',
        ])
        ->assertRedirect(route('event-types.index'));

    expect(EventType::where('user_id', $user->id)->sole()->max_bookings_per_day)->toBeNull();
});

it('store validates booking policy field ranges', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('event-types.store'), [
            'name' => 'Bad Policy',
            'duration_minutes' => 30,
            'buffer_before_minutes' => -1,
            'booking_window_days' => 0,
            'max_bookings_per_day' => 0,
        ])
        ->assertSessionHasErrors(['buffer_before_minutes', 'booking_window_days', 'max_bookings_per_day']);
});

it('guest cannot store an event type', function () {
    $this->post(route('event-types.store'), ['name' => 'Test', 'duration_minutes' => 30])
        ->assertRedirect(route('login'));
});

// ── edit ──────────────────────────────────────────────────────────────────────

it('user can view edit form for their own event type', function () {
    $user = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('event-types.edit', $eventType))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('EventTypes/Edit'));
});

it('user is forbidden from editing another users event type', function () {
    $eventType = EventType::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('event-types.edit', $eventType))
        ->assertForbidden();
});

// ── update ────────────────────────────────────────────────────────────────────

it('user can update their own event type', function () {
    $user = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patch(route('event-types.update', $eventType), [
            'name' => 'Updated Name',
            'duration_minutes' => 60,
        ])
        ->assertRedirect(route('event-types.index'));

    expect($eventType->fresh()->name)->toBe('Updated Name')
        ->and($eventType->fresh()->duration_minutes)->toBe(60);
});

it('user is forbidden from updating another users event type', function () {
    $eventType = EventType::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('event-types.update', $eventType), ['name' => 'Hacked', 'duration_minutes' => 30])
        ->assertForbidden();
});

it('update validates required fields', function () {
    $user = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patch(route('event-types.update', $eventType), [])
        ->assertSessionHasErrors(['name', 'duration_minutes']);
});

// ── destroy ───────────────────────────────────────────────────────────────────

it('user can delete their own event type', function () {
    $user = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('event-types.destroy', $eventType))
        ->assertRedirect(route('event-types.index'));

    expect(EventType::find($eventType->id))->toBeNull();
});

it('user is forbidden from deleting another users event type', function () {
    $eventType = EventType::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('event-types.destroy', $eventType))
        ->assertForbidden();
});

it('cannot delete an event type that has an active upcoming confirmed booking', function () {
    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id]);
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->delete(route('event-types.destroy', $eventType))
        ->assertStatus(422);

    expect(EventType::find($eventType->id))->not->toBeNull()
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('can delete an event type whose bookings are all past, cancelled, or completed', function () {
    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id]);
    Booking::factory()->past()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
    ]);
    Booking::factory()->cancelled()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
    ]);
    Booking::factory()->past()->completed()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
    ]);

    $this->actingAs($host)
        ->delete(route('event-types.destroy', $eventType))
        ->assertRedirect(route('event-types.index'));

    expect(EventType::find($eventType->id))->toBeNull();
});
