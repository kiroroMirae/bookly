<?php

namespace App\Http\Controllers;

use App\Enums\BookingActor;
use App\Enums\BookingEventKind;
use App\Enums\BookingStatus;
use App\Http\Requests\CancelBookingRequest;
use App\Http\Requests\RescheduleBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Booking;
use App\Notifications\GuestBookingCancelled;
use App\Notifications\GuestBookingRescheduled;
use App\Services\SlotGenerator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    private const PAST_PER_PAGE = 15;

    public function index(): Response
    {
        $now = Carbon::now();

        return Inertia::render('Bookings/Index', [
            'upcoming' => fn () => auth()->user()
                ->bookings()
                ->with('eventType', 'events')
                ->where('starts_at', '>=', $now)
                ->orderBy('starts_at')
                ->get(),
            'past' => fn () => auth()->user()
                ->bookings()
                ->with('eventType', 'events')
                ->where('starts_at', '<', $now)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->cursorPaginate(self::PAST_PER_PAGE),
        ]);
    }

    public function cancel(CancelBookingRequest $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        $booking->load('eventType', 'host');

        $reason = $request->validated('cancellation_reason');

        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancellation_reason' => $reason,
        ]);

        $booking->recordEvent(BookingEventKind::Cancelled, BookingActor::Host, filled($reason) ? ['reason' => $reason] : null);

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

        if (array_key_exists('status', $data)) {
            $kind = $booking->status === BookingStatus::Completed
                ? BookingEventKind::Completed
                : BookingEventKind::NoShow;

            $booking->recordEvent($kind, BookingActor::Host);
        }

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

            $previousStartsAt = $booking->starts_at->toIso8601String();

            $booking->update([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($booking->eventType->duration_minutes),
                'reminder_sent_at' => null,
                'ics_sequence' => $booking->ics_sequence + 1,
            ]);

            $booking->recordEvent(BookingEventKind::Rescheduled, BookingActor::Host, [
                'from' => $previousStartsAt,
                'to' => $startsAt->toIso8601String(),
            ]);
        });

        Notification::route('mail', $booking->guest_email)
            ->notify(new GuestBookingRescheduled($booking));

        return redirect()->route('bookings.index');
    }
}
