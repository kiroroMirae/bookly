<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\GuestBookingCancelled;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
