<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const UPCOMING_LIMIT = 5;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $upcomingBookings = $user->bookings()
            ->with('eventType')
            ->whereNot('status', BookingStatus::Cancelled)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(self::UPCOMING_LIMIT)
            ->get()
            ->map(fn ($booking) => [
                'id' => $booking->id,
                'guest_name' => $booking->guest_name,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'event_type_name' => $booking->eventType->name,
                'event_type_color' => $booking->eventType->color,
            ]);

        $bookingsThisWeek = $user->bookings()
            ->whereNot('status', BookingStatus::Cancelled)
            ->whereBetween('starts_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        $activeEventTypes = $user->eventTypes()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('Dashboard', [
            'upcomingBookings' => $upcomingBookings,
            'stats' => [
                'bookingsThisWeek' => $bookingsThisWeek,
                'activeEventTypes' => $activeEventTypes->count(),
            ],
            'eventTypeLinks' => $activeEventTypes->map(fn ($eventType) => [
                'name' => $eventType->name,
                'url' => url("/{$user->username}/{$eventType->slug}"),
            ]),
            'timezone' => $user->timezone,
        ]);
    }
}
