<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\GuestBookingReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Send 24-hour reminder emails for upcoming confirmed bookings';

    public function handle(): int
    {
        $windowStart = now()->addHours(23);
        $windowEnd = now()->addHours(25);

        $bookings = Booking::where('status', BookingStatus::Confirmed)
            ->whereBetween('starts_at', [$windowStart, $windowEnd])
            ->whereNull('reminder_sent_at')
            ->with('eventType', 'host')
            ->get();

        foreach ($bookings as $booking) {
            DB::transaction(function () use ($booking) {
                $fresh = Booking::lockForUpdate()->find($booking->id);

                if ($fresh === null || $fresh->reminder_sent_at !== null) {
                    return;
                }

                Notification::route('mail', $fresh->guest_email)
                    ->notify(new GuestBookingReminder($fresh->load('eventType', 'host')));

                $fresh->update(['reminder_sent_at' => now()]);
            });
        }

        return self::SUCCESS;
    }
}
