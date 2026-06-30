<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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

        return (new MailMessage)
            ->subject("Booking confirmed: {$booking->eventType->name}")
            ->greeting("Hi {$booking->guest_name},")
            ->line('Your booking has been confirmed.')
            ->line("**{$booking->eventType->name}** with {$booking->host->name}")
            ->line($startsAt->format('l, F j, Y \a\t g:i A T'))
            ->line("Duration: {$booking->eventType->duration_minutes} minutes");
    }
}
