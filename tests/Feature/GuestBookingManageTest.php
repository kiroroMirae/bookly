<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Notifications\GuestBookingCancelled;
use App\Notifications\GuestBookingConfirmed;
use App\Notifications\GuestBookingRescheduled;
use App\Notifications\HostBookingCancelledByGuest;
use App\Notifications\HostBookingRescheduled;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2025-01-05 00:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ── helpers ───────────────────────────────────────────────────────────────────

/** @return array{0: User, 1: EventType, 2: Booking} */
function manageSetup(): array
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

function signedManageUrl(Booking $booking, string $routeName = 'booking.manage'): string
{
    return URL::signedRoute($routeName, [
        'username' => 'alice',
        'slug' => 'coffee-chat',
        'booking' => $booking->id,
    ], absolute: false);
}

// ── manage page ───────────────────────────────────────────────────────────────

it('renders the manage page with a valid signature', function () {
    [, , $booking] = manageSetup();

    $this->get(signedManageUrl($booking))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Booking/Manage')
            ->has('booking')
            ->has('slots')
            ->where('canModify', true)
        );
});

it('rejects the manage page without a valid signature', function () {
    [, , $booking] = manageSetup();

    $this->get(route('booking.manage', [
        'username' => 'alice',
        'slug' => 'coffee-chat',
        'booking' => $booking->id,
    ]))->assertForbidden();
});

it('returns 404 when the booking does not belong to the slug', function () {
    [$host, , $booking] = manageSetup();
    EventType::factory()->create(['user_id' => $host->id, 'slug' => 'other-event', 'is_active' => true]);

    $this->get(URL::signedRoute('booking.manage', [
        'username' => 'alice',
        'slug' => 'other-event',
        'booking' => $booking->id,
    ], absolute: false))->assertNotFound();
});

it('marks cancelled bookings as not modifiable on the manage page', function () {
    [, , $booking] = manageSetup();
    $booking->update(['status' => BookingStatus::Cancelled]);

    $this->get(signedManageUrl($booking))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canModify', false));
});

it('allows a date query param on the signed manage url', function () {
    [, , $booking] = manageSetup();

    $this->get(signedManageUrl($booking).'&date=2025-01-13')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('selectedDate', '2025-01-13'));
});

// ── guest cancel ──────────────────────────────────────────────────────────────

it('guest can cancel a booking via signed url', function () {
    Notification::fake();
    [$host, , $booking] = manageSetup();

    $this->patch(signedManageUrl($booking, 'booking.guest-cancel'), [
        'cancellation_reason' => 'Cannot make it',
    ])->assertRedirect();

    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancellation_reason)->toBe('Cannot make it');

    Notification::assertSentTo($host, HostBookingCancelledByGuest::class);
    Notification::assertSentOnDemand(
        GuestBookingCancelled::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'bob@example.com'
    );
});

it('rejects guest cancel without a valid signature', function () {
    [, , $booking] = manageSetup();

    $this->patch(route('booking.guest-cancel', [
        'username' => 'alice',
        'slug' => 'coffee-chat',
        'booking' => $booking->id,
    ]))->assertForbidden();

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('cannot cancel an already cancelled booking', function () {
    [, , $booking] = manageSetup();
    $booking->update(['status' => BookingStatus::Cancelled]);

    $this->patch(signedManageUrl($booking, 'booking.guest-cancel'))
        ->assertStatus(422);
});

it('cannot cancel a past booking', function () {
    [, , $booking] = manageSetup();
    $booking->update([
        'starts_at' => '2025-01-03 09:00:00',
        'ends_at' => '2025-01-03 09:30:00',
    ]);

    $this->patch(signedManageUrl($booking, 'booking.guest-cancel'))
        ->assertStatus(422);
});

// ── guest reschedule ──────────────────────────────────────────────────────────

it('guest can reschedule to an open slot', function () {
    Notification::fake();
    [$host, , $booking] = manageSetup();
    $booking->update(['reminder_sent_at' => now()->subHour()]);

    $this->patch(signedManageUrl($booking, 'booking.reschedule'), [
        'starts_at' => '2025-01-06 10:00:00',
    ])->assertRedirect();

    $booking->refresh();
    expect($booking->starts_at->format('Y-m-d H:i'))->toBe('2025-01-06 10:00')
        ->and($booking->ends_at->format('Y-m-d H:i'))->toBe('2025-01-06 10:30')
        ->and($booking->reminder_sent_at)->toBeNull()
        ->and($booking->status)->toBe(BookingStatus::Confirmed);

    Notification::assertSentTo($host, HostBookingRescheduled::class);
    Notification::assertSentOnDemand(GuestBookingRescheduled::class);
});

it('rejects rescheduling to a taken slot', function () {
    [$host, $eventType, $booking] = manageSetup();
    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 10:00:00',
        'ends_at' => '2025-01-06 10:30:00',
        'status' => BookingStatus::Confirmed,
    ]);

    $this->patch(signedManageUrl($booking, 'booking.reschedule'), [
        'starts_at' => '2025-01-06 10:00:00',
    ])->assertStatus(422);

    expect($booking->refresh()->starts_at->format('H:i'))->toBe('09:00');
});

it('allows rescheduling to a slot adjacent to the current one', function () {
    [, , $booking] = manageSetup();

    $this->patch(signedManageUrl($booking, 'booking.reschedule'), [
        'starts_at' => '2025-01-06 09:30:00',
    ])->assertRedirect();

    expect($booking->refresh()->starts_at->format('H:i'))->toBe('09:30');
});

it('cannot reschedule a cancelled booking', function () {
    [, , $booking] = manageSetup();
    $booking->update(['status' => BookingStatus::Cancelled]);

    $this->patch(signedManageUrl($booking, 'booking.reschedule'), [
        'starts_at' => '2025-01-06 10:00:00',
    ])->assertStatus(422);
});

it('validates reschedule starts_at', function () {
    [, , $booking] = manageSetup();

    $this->patch(signedManageUrl($booking, 'booking.reschedule'), [
        'starts_at' => 'not-a-date',
    ])->assertSessionHasErrors('starts_at');
});

// ── confirmation email link ───────────────────────────────────────────────────

it('includes a signed manage link in the guest confirmation email', function () {
    [, , $booking] = manageSetup();
    $booking->load('eventType', 'host');

    $mail = (new GuestBookingConfirmed($booking))->toMail((object) []);

    expect($mail->actionUrl)->toContain("/manage/{$booking->id}")
        ->and($mail->actionUrl)->toContain('signature=');
});
