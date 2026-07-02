<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires authentication', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('renders the dashboard with stats and upcoming bookings', function () {
    $user = User::factory()->create(['username' => 'alice']);
    $eventType = EventType::factory()->create(['user_id' => $user->id, 'is_active' => true]);
    EventType::factory()->inactive()->create(['user_id' => $user->id]);

    Booking::factory()->count(2)->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $user->id,
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('upcomingBookings', 2)
            ->where('stats.activeEventTypes', 1)
            ->has('stats.bookingsThisWeek')
            ->has('eventTypeLinks')
        );
});

it('excludes cancelled and past bookings from upcoming', function () {
    $user = User::factory()->create(['username' => 'alice']);
    $eventType = EventType::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    Booking::factory()->cancelled()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $user->id,
    ]);
    Booking::factory()->past()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $user->id,
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('upcomingBookings', 0));
});

it('limits upcoming bookings to five', function () {
    $user = User::factory()->create(['username' => 'alice']);
    $eventType = EventType::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    foreach (range(1, 7) as $day) {
        Booking::factory()->create([
            'event_type_id' => $eventType->id,
            'host_user_id' => $user->id,
            'starts_at' => now()->addDays($day)->setTime(10, 0),
            'ends_at' => now()->addDays($day)->setTime(10, 30),
        ]);
    }

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('upcomingBookings', 5));
});

it('only shows the authenticated user\'s bookings', function () {
    $user = User::factory()->create(['username' => 'alice']);
    Booking::factory()->create(); // belongs to another host

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('upcomingBookings', 0));
});
