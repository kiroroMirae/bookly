<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Services\IcsGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class GuestBookingConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking;
        $startsAt = $booking->starts_at->setTimezone($booking->guest_timezone);

        $manageUrl = url(URL::signedRoute('booking.manage', [
            'username' => $booking->host->username,
            'slug' => $booking->eventType->slug,
            'booking' => $booking->id,
        ], absolute: false));

        return (new MailMessage)
            ->subject("Booking confirmed: {$booking->eventType->name}")
            ->greeting("Hi {$booking->guest_name},")
            ->line('Your booking has been confirmed.')
            ->line("**{$booking->eventType->name}** with {$booking->host->name}")
            ->line($startsAt->format('l, F j, Y \a\t g:i A T'))
            ->line("Duration: {$booking->eventType->duration_minutes} minutes")
            ->line('Need to make a change? Use the link below to cancel or reschedule.')
            ->action('Manage booking', $manageUrl)
            ->attachData(($ics = new IcsGenerator)->forBooking($booking), 'invite.ics', [
                'mime' => $ics->mimeType(),
            ]);
    }
}
