<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Notifications\GuestBookingCancelled;
use App\Notifications\GuestBookingConfirmed;
use App\Notifications\GuestBookingReminder;
use App\Notifications\GuestBookingRescheduled;
use App\Notifications\HostBookingCancelledByGuest;
use App\Notifications\HostBookingRescheduled;
use App\Notifications\HostNewBooking;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// ── shared setup ──────────────────────────────────────────────────────────────

function notifHost(): User
{
    return User::factory()->create(['username' => 'alice', 'timezone' => 'UTC']);
}

function notifEventType(User $host): EventType
{
    return EventType::factory()->create([
        'user_id' => $host->id,
        'slug' => 'coffee-chat',
        'duration_minutes' => 30,
        'is_active' => true,
    ]);
}

function notifWindow(User $host): void
{
    AvailabilityWindow::factory()->create([
        'user_id' => $host->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '11:00',
    ]);
}

function bookingPayload(): array
{
    return [
        'guest_name' => 'Bob Smith',
        'guest_email' => 'bob@example.com',
        'guest_timezone' => 'UTC',
        'starts_at' => '2025-01-06 09:00:00',
    ];
}

// ── booking confirmation ──────────────────────────────────────────────────────

it('sends GuestBookingConfirmed to the guest when a booking is created', function () {
    Notification::fake();
    Carbon::setTestNow('2025-01-05 00:00:00');

    $host = notifHost();
    notifEventType($host);
    notifWindow($host);

    $this->post(
        route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']),
        bookingPayload()
    )->assertRedirect();

    Notification::assertSentOnDemand(
        GuestBookingConfirmed::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'bob@example.com'
    );
})->afterEach(fn () => Carbon::setTestNow());

it('sends HostNewBooking to the host when a booking is created', function () {
    Notification::fake();
    Carbon::setTestNow('2025-01-05 00:00:00');

    $host = notifHost();
    notifEventType($host);
    notifWindow($host);

    $this->post(
        route('booking.store', ['username' => 'alice', 'slug' => 'coffee-chat']),
        bookingPayload()
    )->assertRedirect();

    Notification::assertSentTo($host, HostNewBooking::class);
})->afterEach(fn () => Carbon::setTestNow());

// ── cancellation ──────────────────────────────────────────────────────────────

it('sends GuestBookingCancelled to the guest when the host cancels', function () {
    Notification::fake();

    $host = User::factory()->create();
    $booking = Booking::factory()->create([
        'host_user_id' => $host->id,
        'guest_email' => 'bob@example.com',
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($host)
        ->patch(route('bookings.cancel', $booking))
        ->assertRedirect(route('bookings.index'));

    Notification::assertSentOnDemand(
        GuestBookingCancelled::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'bob@example.com'
    );
});

// ── ics attachments ───────────────────────────────────────────────────────────

function attachmentBooking(): Booking
{
    $host = User::factory()->create();
    $eventType = EventType::factory()->create(['user_id' => $host->id]);

    return Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'ics_sequence' => 1,
    ]);
}

it('attaches a calendar invite', function (string $notificationClass, string $method) {
    $booking = attachmentBooking();

    $mail = (new $notificationClass($booking))->toMail($booking->host);

    expect($mail->rawAttachments)->toHaveCount(1);

    $attachment = $mail->rawAttachments[0];

    expect($attachment['name'])->toBe('invite.ics')
        ->and($attachment['options']['mime'])->toContain("method={$method}")
        ->and($attachment['data'])->toContain("METHOD:{$method}")
        ->and($attachment['data'])->toContain("UID:booking-{$booking->id}@bookly")
        ->and($attachment['data'])->toContain('SEQUENCE:1')
        ->and($attachment['data'])->toContain($method === 'CANCEL' ? 'STATUS:CANCELLED' : 'STATUS:CONFIRMED');
})->with([
    'guest confirmed' => [GuestBookingConfirmed::class, 'REQUEST'],
    'host new booking' => [HostNewBooking::class, 'REQUEST'],
    'guest rescheduled' => [GuestBookingRescheduled::class, 'REQUEST'],
    'host rescheduled' => [HostBookingRescheduled::class, 'REQUEST'],
    'guest cancelled' => [GuestBookingCancelled::class, 'CANCEL'],
    'host cancelled by guest' => [HostBookingCancelledByGuest::class, 'CANCEL'],
]);

it('does not attach an invite to the reminder email', function () {
    $booking = attachmentBooking();
    $mail = (new GuestBookingReminder($booking))->toMail($booking->host);

    expect($mail->rawAttachments)->toBe([]);
});
