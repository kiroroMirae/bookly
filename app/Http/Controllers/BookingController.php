<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Http\Requests\RescheduleBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Booking;
use App\Notifications\GuestBookingCancelled;
use App\Notifications\GuestBookingRescheduled;
use App\Services\SlotGenerator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function index(): Response
    {
        $now = Carbon::now();

        $bookings = auth()->user()
            ->bookings()
            ->with('eventType')
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn ($b) => $b->starts_at->gte($now) ? 'upcoming' : 'past');

        return Inertia::render('Bookings/Index', [
            'upcoming' => $bookings->get('upcoming', collect())->values(),
            'past' => $bookings->get('past', collect())->values(),
        ]);
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        $booking->load('eventType', 'host');

        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancellation_reason' => $request->input('cancellation_reason'),
        ]);

        Notification::route('mail', $booking->guest_email)
            ->notify(new GuestBookingCancelled($booking));

        return redirect()->route('bookings.index');
    }

    public function update(UpdateBookingRequest $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('update', $booking);

        $data = $request->validated();

        if (array_key_exists('status', $data)) {
            $canTransition = $booking->status === BookingStatus::Confirmed && $booking->ends_at->isPast();

            abort_unless($canTransition, 422, 'Only past confirmed bookings can be marked completed or no-show.');
        }

        $booking->update($data);

        return redirect()->route('bookings.index');
    }

    public function reschedule(RescheduleBookingRequest $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('update', $booking);

        abort_unless(
            $booking->status === BookingStatus::Confirmed && $booking->starts_at->isFuture(),
            422,
            'This booking can no longer be modified.'
        );

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

        Notification::route('mail', $booking->guest_email)
            ->notify(new GuestBookingRescheduled($booking));

        return redirect()->route('bookings.index');
    }
}
