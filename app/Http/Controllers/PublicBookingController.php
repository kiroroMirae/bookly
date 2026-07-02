<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\EventType;
use App\Notifications\GuestBookingConfirmed;
use App\Notifications\HostNewBooking;
use App\Services\SlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class PublicBookingController extends Controller
{
    public function show(Request $request, string $username, string $slug): Response
    {
        $eventType = EventType::whereHas('user', fn ($q) => $q->where('username', $username))
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with('user')
            ->firstOrFail();

        $guestTimezone = $request->query('tz', 'UTC');
        if (! in_array($guestTimezone, timezone_identifiers_list(), true)) {
            $guestTimezone = 'UTC';
        }

        $dateParam = $request->query('date');
        $date = $dateParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)
            ? CarbonImmutable::parse($dateParam)
            : CarbonImmutable::today($eventType->user->timezone);

        $slots = (new SlotGenerator)->forDate($eventType, $date, $guestTimezone);

        return Inertia::render('Public/Booking/Show', [
            'eventType' => $eventType->only('id', 'name', 'slug', 'description', 'duration_minutes', 'color'),
            'host' => $eventType->user->only('name', 'username'),
            'slots' => $slots,
            'selectedDate' => $date->format('Y-m-d'),
            'guestTimezone' => $guestTimezone,
        ]);
    }

    public function store(StoreBookingRequest $request, string $username, string $slug): RedirectResponse
    {
        $eventType = EventType::whereHas('user', fn ($q) => $q->where('username', $username))
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with('user')
            ->firstOrFail();

        $data = $request->validated();
        $startsAt = CarbonImmutable::parse($data['starts_at'], 'UTC');
        $guestTimezone = $data['guest_timezone'];

        $booking = DB::transaction(function () use ($eventType, $data, $startsAt, $guestTimezone) {
            $host = $eventType->user;

            $host->bookings()->lockForUpdate()->get();

            $date = CarbonImmutable::parse($startsAt->format('Y-m-d'));
            $openSlots = (new SlotGenerator)->forDate($eventType, $date, $guestTimezone);

            $isOpen = collect($openSlots)->contains(
                fn ($slot) => CarbonImmutable::parse($slot['starts_at'])->eq($startsAt)
            );

            if (! $isOpen) {
                abort(422, 'That time slot is no longer available.');
            }

            return Booking::create([
                'event_type_id' => $eventType->id,
                'host_user_id' => $host->id,
                'guest_name' => $data['guest_name'],
                'guest_email' => $data['guest_email'],
                'guest_timezone' => $guestTimezone,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($eventType->duration_minutes),
                'status' => BookingStatus::Confirmed,
            ]);
        });

        $booking->load('eventType', 'host');

        Notification::route('mail', $booking->guest_email)
            ->notify(new GuestBookingConfirmed($booking));

        $booking->host->notify(new HostNewBooking($booking));

        return redirect()->route('booking.confirmation', [
            'username' => $username,
            'slug' => $slug,
            'booking' => $booking->id,
        ]);
    }

    public function confirmation(string $username, string $slug, Booking $booking): Response
    {
        abort_unless(
            $booking->eventType->slug === $slug &&
            $booking->host->username === $username,
            404
        );

        return Inertia::render('Public/Booking/Confirmation', [
            'booking' => $booking->only('guest_name', 'guest_email', 'starts_at', 'ends_at', 'guest_timezone'),
            'eventType' => $booking->eventType->only('name', 'duration_minutes', 'color'),
            'host' => $booking->host->only('name'),
        ]);
    }
}
