<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

const PAST_PER_PAGE = 15;

/**
 * Seed $count past bookings for the given host with strictly increasing
 * starts_at values (each one minute more recent than the last), so the
 * chronological order is unambiguous — the last-created booking is also
 * the most recent one.
 */
function seedPastBookings(User $host, EventType $eventType, int $count): Collection
{
    return Booking::factory()
        ->count($count)
        ->state(new Sequence(
            fn (Sequence $sequence) => [
                'starts_at' => now()->subMinutes($count - $sequence->index),
                'ends_at' => now()->subMinutes($count - $sequence->index)->addMinutes(30),
            ]
        ))
        ->create([
            'host_user_id' => $host->id,
            'event_type_id' => $eventType->id,
            'status' => BookingStatus::Confirmed,
        ]);
}

// ── page size ─────────────────────────────────────────────────────────────────

it('paginates past bookings at the configured page size, most-recent first', function () {
    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id]);
    $bookings = seedPastBookings($host, $eventType, 500);
    $mostRecentPastBooking = $bookings->last();

    $this->actingAs($host)
        ->get(route('bookings.index'))
        ->assertInertia(fn ($page) => $page->component('Bookings/Index')
            ->has('past.data', PAST_PER_PAGE)
            ->where('past.data.0.id', $mostRecentPastBooking->id)
            ->whereNotNull('past.next_cursor')
        );
});

// ── second page via cursor ───────────────────────────────────────────────────

it('returns a disjoint second page of past bookings when following the cursor', function () {
    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id]);
    $bookings = seedPastBookings($host, $eventType, 20);
    $allIdsMostRecentFirst = $bookings->sortByDesc('starts_at')->pluck('id')->values();

    $firstResponse = $this->actingAs($host)->get(route('bookings.index'));
    $firstResponse->assertOk();

    $firstPage = $firstResponse->inertiaPage();
    $firstPageIds = collect($firstPage['props']['past']['data'])->pluck('id');
    $nextPageUrl = $firstPage['props']['past']['next_page_url'];
    $version = $firstPage['version'];

    expect($firstPageIds->all())->toEqual($allIdsMostRecentFirst->take(PAST_PER_PAGE)->all())
        ->and($nextPageUrl)->not->toBeNull();

    $secondResponse = $this->actingAs($host)->get($nextPageUrl, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Data' => 'past',
        'X-Inertia-Partial-Component' => 'Bookings/Index',
    ]);
    $secondResponse->assertOk();

    $secondPageIds = collect($secondResponse->json('props.past.data'))->pluck('id');

    expect($firstPageIds->intersect($secondPageIds))->toBeEmpty()
        ->and($secondPageIds->all())->toEqual($allIdsMostRecentFirst->slice(PAST_PER_PAGE)->values()->all());
});

// ── tiebreaker correctness ───────────────────────────────────────────────────

it('returns no duplicates or skips across pages when past bookings share an identical starts_at', function () {
    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id]);
    $tiedStartsAt = now()->subDay();

    $bookings = Booking::factory()
        ->count(20)
        ->create([
            'host_user_id' => $host->id,
            'event_type_id' => $eventType->id,
            'starts_at' => $tiedStartsAt,
            'ends_at' => $tiedStartsAt->clone()->addMinutes(30),
            'status' => BookingStatus::Confirmed,
        ]);
    $allIdsDescending = $bookings->sortByDesc('id')->pluck('id')->values();

    $firstResponse = $this->actingAs($host)->get(route('bookings.index'));
    $firstResponse->assertOk();

    $firstPage = $firstResponse->inertiaPage();
    $firstPageIds = collect($firstPage['props']['past']['data'])->pluck('id');
    $nextPageUrl = $firstPage['props']['past']['next_page_url'];
    $version = $firstPage['version'];

    expect($firstPageIds->all())->toEqual($allIdsDescending->take(PAST_PER_PAGE)->all())
        ->and($nextPageUrl)->not->toBeNull();

    $secondResponse = $this->actingAs($host)->get($nextPageUrl, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Data' => 'past',
        'X-Inertia-Partial-Component' => 'Bookings/Index',
    ]);
    $secondResponse->assertOk();

    $secondPageIds = collect($secondResponse->json('props.past.data'))->pluck('id');

    expect($firstPageIds->intersect($secondPageIds))->toBeEmpty()
        ->and($firstPageIds->concat($secondPageIds)->sort()->values()->all())
        ->toEqual($allIdsDescending->sort()->values()->all());
});

// ── upcoming stays eager ─────────────────────────────────────────────────────

it('keeps upcoming bookings fully eager and unpaginated', function () {
    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id]);

    Booking::factory()->count(25)->create([
        'host_user_id' => $host->id,
        'event_type_id' => $eventType->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->get(route('bookings.index'))
        ->assertInertia(fn ($page) => $page->component('Bookings/Index')
            ->has('upcoming', 25)
        );
});

// ── empty state ───────────────────────────────────────────────────────────────

it('returns an empty past page when the host has no past bookings', function () {
    $host = User::factory()->create();

    $this->actingAs($host)
        ->get(route('bookings.index'))
        ->assertInertia(fn ($page) => $page->component('Bookings/Index')
            ->has('past.data', 0)
        );
});

// ── phase-10 non-regression ──────────────────────────────────────────────────

it('exposes audit events and cancellation reason for a cancelled booking on the paginated past list', function () {
    Notification::fake();

    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id]);
    $booking = Booking::factory()->past()->create([
        'host_user_id' => $host->id,
        'event_type_id' => $eventType->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.cancel', $booking), ['cancellation_reason' => 'Emergency']);

    $this->actingAs($host)
        ->get(route('bookings.index'))
        ->assertInertia(fn ($page) => $page->component('Bookings/Index')
            ->has('past.data.0.events', 1)
            ->where('past.data.0.cancellation_reason', 'Emergency')
        );
});
