<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-07-03 00:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @return array{0: User, 1: EventType, 2: string} */
function calendarFeedSetup(): array
{
    $host = User::factory()->create(['username' => 'alice', 'timezone' => 'UTC']);
    $token = Str::random(64);
    $host->forceFill(['calendar_feed_token' => $token])->save();

    $eventType = EventType::factory()->create([
        'user_id' => $host->id,
        'slug' => 'coffee-chat',
        'duration_minutes' => 30,
    ]);

    return [$host, $eventType, $token];
}

function feedBooking(EventType $eventType, array $attributes = []): Booking
{
    return Booking::factory()->create(array_merge([
        'event_type_id' => $eventType->id,
        'host_user_id' => $eventType->user_id,
        'starts_at' => now()->addDays(3)->setTime(10, 0, 0),
        'ends_at' => now()->addDays(3)->setTime(10, 30, 0),
        'status' => BookingStatus::Confirmed,
    ], $attributes));
}

// ── feed access ──────────────────────────────────────────────────────────────

it('serves a calendar for a valid feed token', function () {
    [, $eventType, $token] = calendarFeedSetup();
    $booking = feedBooking($eventType);

    $response = $this->get("/calendar/{$token}.ics");

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

    expect($response->getContent())->toContain('BEGIN:VCALENDAR')
        ->and($response->getContent())->toContain('METHOD:PUBLISH')
        ->and($response->getContent())->toContain("UID:booking-{$booking->id}@bookly");
});

it('returns 404 for an unknown feed token', function () {
    calendarFeedSetup();

    $this->get('/calendar/'.Str::random(64).'.ics')->assertNotFound();
});

it('throttles the feed endpoint', function () {
    $middleware = Route::getRoutes()->getByName('calendar-feed.show')->gatherMiddleware();

    expect($middleware)->toContain('throttle:30,1');
});

// ── feed contents ────────────────────────────────────────────────────────────

it('excludes cancelled bookings from the feed', function () {
    [, $eventType, $token] = calendarFeedSetup();
    $cancelled = feedBooking($eventType, [
        'status' => BookingStatus::Cancelled,
        'cancellation_reason' => 'No longer needed',
    ]);

    $content = $this->get("/calendar/{$token}.ics")->getContent();

    expect($content)->not->toContain("UID:booking-{$cancelled->id}@bookly");
});

it('includes recent completed and no_show bookings', function () {
    [, $eventType, $token] = calendarFeedSetup();
    $completed = feedBooking($eventType, [
        'status' => BookingStatus::Completed,
        'starts_at' => now()->subDays(5)->setTime(10, 0, 0),
        'ends_at' => now()->subDays(5)->setTime(10, 30, 0),
    ]);
    $noShow = feedBooking($eventType, [
        'status' => BookingStatus::NoShow,
        'starts_at' => now()->subDays(4)->setTime(10, 0, 0),
        'ends_at' => now()->subDays(4)->setTime(10, 30, 0),
    ]);

    $content = $this->get("/calendar/{$token}.ics")->getContent();

    expect($content)->toContain("UID:booking-{$completed->id}@bookly")
        ->and($content)->toContain("UID:booking-{$noShow->id}@bookly");
});

it('excludes bookings older than 90 days', function () {
    [, $eventType, $token] = calendarFeedSetup();
    $stale = feedBooking($eventType, [
        'status' => BookingStatus::Completed,
        'starts_at' => now()->subDays(120)->setTime(10, 0, 0),
        'ends_at' => now()->subDays(120)->setTime(10, 30, 0),
    ]);

    $content = $this->get("/calendar/{$token}.ics")->getContent();

    expect($content)->not->toContain("UID:booking-{$stale->id}@bookly");
});

it('never includes another host\'s bookings', function () {
    [, , $token] = calendarFeedSetup();

    $otherHost = User::factory()->create(['username' => 'bob']);
    $otherEventType = EventType::factory()->create(['user_id' => $otherHost->id]);
    $otherBooking = feedBooking($otherEventType);

    $content = $this->get("/calendar/{$token}.ics")->getContent();

    expect($content)->not->toContain("UID:booking-{$otherBooking->id}@bookly");
});

// ── token provisioning ───────────────────────────────────────────────────────

it('lazily creates a feed token when the profile page is opened', function () {
    $user = User::factory()->create();

    expect($user->calendar_feed_token)->toBeNull();

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->has('calendarFeedUrl'));

    expect($user->refresh()->calendar_feed_token)->not->toBeNull();
});

it('reuses the existing feed token on subsequent profile visits', function () {
    [$host, , $token] = calendarFeedSetup();

    $this->actingAs($host)->get('/profile')->assertOk();

    expect($host->refresh()->calendar_feed_token)->toBe($token);
});

// ── token regeneration ───────────────────────────────────────────────────────

it('requires authentication to regenerate the feed token', function () {
    $this->post('/calendar-feed/regenerate')->assertRedirect('/login');
});

it('regenerating rotates the token and invalidates the old feed URL', function () {
    [$host, $eventType, $oldToken] = calendarFeedSetup();
    feedBooking($eventType);

    $this->get("/calendar/{$oldToken}.ics")->assertOk();

    $this->actingAs($host)
        ->post('/calendar-feed/regenerate')
        ->assertRedirect();

    $newToken = $host->refresh()->calendar_feed_token;

    expect($newToken)->not->toBe($oldToken);

    $this->get("/calendar/{$oldToken}.ics")->assertNotFound();
    $this->get("/calendar/{$newToken}.ics")->assertOk();
});
