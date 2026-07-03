<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Http\Requests\RegenerateCalendarFeedTokenRequest;
use App\Models\User;
use App\Services\IcsGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CalendarFeedController extends Controller
{
    private const HISTORY_DAYS = 90;

    private const CACHE_SECONDS = 300;

    public function __construct(private readonly IcsGenerator $icsGenerator) {}

    /**
     * Serve the host's subscribable calendar feed. Unauthenticated by design:
     * calendar clients cannot log in, so the token itself is the secret.
     */
    public function show(string $token): Response
    {
        $host = User::where('calendar_feed_token', $token)->firstOrFail();

        $bookings = $host->bookings()
            ->with('eventType')
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Completed, BookingStatus::NoShow])
            ->where('starts_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->orderBy('starts_at')
            ->get();

        return response($this->icsGenerator->forHostFeed($host, $bookings))
            ->withHeaders([
                'Content-Type' => $this->icsGenerator->feedMimeType(),
                'Content-Disposition' => 'inline; filename="bookly-bookings.ics"',
                'Cache-Control' => 'private, max-age='.self::CACHE_SECONDS,
            ]);
    }

    /**
     * Rotate the feed token, immediately invalidating the previous feed URL.
     */
    public function regenerate(RegenerateCalendarFeedTokenRequest $request): RedirectResponse
    {
        $request->user()->forceFill(['calendar_feed_token' => Str::random(64)])->save();

        return back();
    }
}
