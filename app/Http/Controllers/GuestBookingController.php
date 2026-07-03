<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Http\Requests\RescheduleBookingRequest;
use App\Models\Booking;
use App\Notifications\GuestBookingCancelled;
use App\Notifications\GuestBookingRescheduled;
use App\Notifications\HostBookingCancelledByGuest;
use App\Notifications\HostBookingRescheduled;
use App\Services\SlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class GuestBookingController extends Controller
{
    public function show(Request $request, string $username, string $slug, Booking $booking): Response
    {
        $this->assertBookingMatchesUrl($booking, $username, $slug);

        $booking->load('eventType', 'host');

        $dateParam = $request->query('date');
        $date = $dateParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)
            ? CarbonImmutable::parse($dateParam)
            : CarbonImmutable::parse($booking->starts_at->format('Y-m-d'));

        $canModify = $this->canModify($booking);

        $slots = $canModify
            ? (new SlotGenerator)->forDate($booking->eventType, $date, $booking->guest_timezone, $booking->id)
            : [];

        return Inertia::render('Public/Booking/Manage', [
            'booking' => [
                'id' => $booking->id,
                'guest_name' => $booking->guest_name,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'guest_timezone' => $booking->guest_timezone,
                'status' => $booking->status->value,
            ],
            'eventType' => $booking->eventType->only('name', 'slug', 'duration_minutes', 'color'),
            'host' => $booking->host->only('name', 'username'),
            'slots' => $slots,
            'selectedDate' => $date->format('Y-m-d'),
            'canModify' => $canModify,
            'cancelUrl' => $this->signedActionUrl('booking.guest-cancel', $username, $slug, $booking),
            'rescheduleUrl' => $this->signedActionUrl('booking.reschedule', $username, $slug, $booking),
            'manageUrl' => $this->signedActionUrl('booking.manage', $username, $slug, $booking),
        ]);
    }

    public function cancel(Request $request, string $username, string $slug, Booking $booking): RedirectResponse
    {
        $this->assertBookingMatchesUrl($booking, $username, $slug);
        $this->assertModifiable($booking);

        $booking->load('eventType', 'host');

        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancellation_reason' => $request->input('cancellation_reason'),
        ]);

        $booking->host->notify(new HostBookingCancelledByGuest($booking));

        Notification::route('mail', $booking->guest_email)
            ->notify(new GuestBookingCancelled($booking));

        return redirect()->to($this->signedActionUrl('booking.manage', $username, $slug, $booking));
    }

    public function reschedule(RescheduleBookingRequest $request, string $username, string $slug, Booking $booking): RedirectResponse
    {
        $this->assertBookingMatchesUrl($booking, $username, $slug);
        $this->assertModifiable($booking);

        $booking->load('eventType', 'host');

        $startsAt = CarbonImmutable::parse($request->validated()['starts_at'], 'UTC');

        DB::transaction(function () use ($booking, $startsAt) {
            $booking->host->bookings()->lockForUpdate()->get();

            $date = CarbonImmutable::parse($startsAt->format('Y-m-d'));
            $openSlots = (new SlotGenerator)->forDate(
                $booking->eventType, $date, $booking->guest_timezone, $booking->id
            );

            $isOpen = collect($openSlots)->contains(
                fn ($slot) => CarbonImmutable::parse($slot['starts_at'])->eq($startsAt)
            );

            if (! $isOpen) {
                abort(422, 'That time slot is no longer available.');
            }

            $booking->update([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($booking->eventType->duration_minutes),
                'reminder_sent_at' => null,
                'ics_sequence' => $booking->ics_sequence + 1,
            ]);
        });

        $booking->host->notify(new HostBookingRescheduled($booking));

        Notification::route('mail', $booking->guest_email)
            ->notify(new GuestBookingRescheduled($booking));

        return redirect()->to($this->signedActionUrl('booking.manage', $username, $slug, $booking));
    }

    private function assertBookingMatchesUrl(Booking $booking, string $username, string $slug): void
    {
        abort_unless(
            $booking->eventType->slug === $slug &&
            $booking->host->username === $username,
            404
        );
    }

    private function assertModifiable(Booking $booking): void
    {
        abort_unless($this->canModify($booking), 422, 'This booking can no longer be modified.');
    }

    private function canModify(Booking $booking): bool
    {
        return $booking->status === BookingStatus::Confirmed
            && $booking->starts_at->isFuture();
    }

    private function signedActionUrl(string $routeName, string $username, string $slug, Booking $booking): string
    {
        return URL::signedRoute($routeName, [
            'username' => $username,
            'slug' => $slug,
            'booking' => $booking->id,
        ], absolute: false);
    }
}
