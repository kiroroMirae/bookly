<?php

declare(strict_types=1);

use App\Enums\BookingActor;
use App\Enums\BookingEventKind;
use App\Enums\BookingStatus;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\EventType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2025-01-05 00:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @return array{0: User, 1: EventType, 2: Booking} */
function auditSetup(): array
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
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'guest_email' => 'bob@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Confirmed,
    ]);

    return [$host, $eventType, $booking];
}

function auditSignedUrl(Booking $booking, string $routeName): string
{
    return URL::signedRoute($routeName, [
        'username' => 'alice',
        'slug' => 'coffee-chat',
        'booking' => $booking->id,
    ], absolute: false);
}

// ── created ───────────────────────────────────────────────────────────────────

it('records a created event when a guest books', function () {
    Notification::fake();
    [$host, $eventType] = auditSetup();

    $this->post(route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']), [
        'guest_name' => 'Bob',
        'guest_email' => 'bob@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2025-01-06 10:00:00',
    ])->assertRedirect();

    $booking = Booking::where('guest_name', 'Bob')->firstOrFail();
    $event = $booking->events()->sole();

    expect($event->event)->toBe(BookingEventKind::Created)
        ->and($event->actor)->toBe(BookingActor::Guest);
});

// ── cancel ────────────────────────────────────────────────────────────────────

it('records a cancelled event with reason when the host cancels', function () {
    Notification::fake();
    [$host, , $booking] = auditSetup();

    $this->actingAs($host)
        ->patch(route('bookings.cancel', $booking), ['cancellation_reason' => 'Emergency'])
        ->assertRedirect(route('bookings.index'));

    $event = $booking->events()->sole();

    expect($event->event)->toBe(BookingEventKind::Cancelled)
        ->and($event->actor)->toBe(BookingActor::Host)
        ->and($event->metadata)->toBe(['reason' => 'Emergency']);
});

it('records a cancelled event when the guest cancels', function () {
    Notification::fake();
    [, , $booking] = auditSetup();

    $this->patch(auditSignedUrl($booking, 'booking.guest-cancel'), [
        'cancellation_reason' => 'Cannot make it',
    ])->assertRedirect();

    $event = $booking->events()->sole();

    expect($event->event)->toBe(BookingEventKind::Cancelled)
        ->and($event->actor)->toBe(BookingActor::Guest)
        ->and($event->metadata)->toBe(['reason' => 'Cannot make it']);
});

it('rejects a host cancellation reason over 1000 characters', function () {
    [$host, , $booking] = auditSetup();

    $this->actingAs($host)
        ->patch(route('bookings.cancel', $booking), ['cancellation_reason' => str_repeat('x', 1001)])
        ->assertSessionHasErrors('cancellation_reason');

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('rejects a guest cancellation reason over 1000 characters', function () {
    [, , $booking] = auditSetup();

    $this->patch(auditSignedUrl($booking, 'booking.guest-cancel'), [
        'cancellation_reason' => str_repeat('x', 1001),
    ])->assertSessionHasErrors('cancellation_reason');

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

// ── reschedule ────────────────────────────────────────────────────────────────

it('records a rescheduled event with old and new times when the host reschedules', function () {
    Notification::fake();
    [$host, , $booking] = auditSetup();

    $this->actingAs($host)
        ->patch(route('bookings.reschedule', $booking), ['starts_at' => '2025-01-06 10:00:00'])
        ->assertRedirect(route('bookings.index'));

    $event = $booking->events()->sole();

    expect($event->event)->toBe(BookingEventKind::Rescheduled)
        ->and($event->actor)->toBe(BookingActor::Host)
        ->and($event->metadata['from'])->toBe('2025-01-06T09:00:00+00:00')
        ->and($event->metadata['to'])->toBe('2025-01-06T10:00:00+00:00');
});

it('records a rescheduled event when the guest reschedules', function () {
    Notification::fake();
    [, , $booking] = auditSetup();

    $this->patch(auditSignedUrl($booking, 'booking.reschedule'), [
        'starts_at' => '2025-01-06 10:00:00',
    ])->assertRedirect();

    $event = $booking->events()->sole();

    expect($event->event)->toBe(BookingEventKind::Rescheduled)
        ->and($event->actor)->toBe(BookingActor::Guest)
        ->and($event->metadata['from'])->toBe('2025-01-06T09:00:00+00:00')
        ->and($event->metadata['to'])->toBe('2025-01-06T10:00:00+00:00');
});

it('does not record an event when a reschedule fails', function () {
    [$host, $eventType, $booking] = auditSetup();
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

    expect($booking->events()->count())->toBe(0);
});

// ── status marks ──────────────────────────────────────────────────────────────

it('records a completed event when the host marks a past booking completed', function () {
    [$host, $eventType] = auditSetup();
    $booking = Booking::factory()->past()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.update', $booking), ['status' => 'completed'])
        ->assertRedirect(route('bookings.index'));

    $event = $booking->events()->sole();

    expect($event->event)->toBe(BookingEventKind::Completed)
        ->and($event->actor)->toBe(BookingActor::Host);
});

it('records a no_show event when the host marks a past booking no-show', function () {
    [$host, $eventType] = auditSetup();
    $booking = Booking::factory()->past()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.update', $booking), ['status' => 'no_show'])
        ->assertRedirect(route('bookings.index'));

    expect($booking->events()->sole()->event)->toBe(BookingEventKind::NoShow);
});

it('does not record an event when only host notes change', function () {
    [$host, , $booking] = auditSetup();

    $this->actingAs($host)
        ->patch(route('bookings.update', $booking), ['host_notes' => 'VIP guest'])
        ->assertRedirect(route('bookings.index'));

    expect($booking->events()->count())->toBe(0);
});

// ── index exposure ────────────────────────────────────────────────────────────

it('includes booking events on the host bookings index', function () {
    Notification::fake();
    [$host, , $booking] = auditSetup();

    $this->actingAs($host)
        ->patch(route('bookings.cancel', $booking), ['cancellation_reason' => 'Emergency']);

    $this->actingAs($host)
        ->get(route('bookings.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Bookings/Index')
            ->has('upcoming.0.events', 1)
            ->where('upcoming.0.events.0.event', 'cancelled')
        );
});

it('deletes events when the booking is deleted', function () {
    Notification::fake();
    [$host, , $booking] = auditSetup();

    $this->actingAs($host)
        ->patch(route('bookings.cancel', $booking), ['cancellation_reason' => null]);

    expect($booking->events()->count())->toBe(1);

    $booking->delete();

    expect(BookingEvent::count())->toBe(0);
});
