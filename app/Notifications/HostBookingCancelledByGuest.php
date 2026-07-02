<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HostBookingCancelledByGuest extends Notification implements ShouldQueue
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
        $startsAt = $booking->starts_at->setTimezone($notifiable->timezone ?? 'UTC');

        $mail = (new MailMessage)
            ->subject("Booking cancelled by guest: {$booking->eventType->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line('A guest has cancelled their booking.')
            ->line("**Guest:** {$booking->guest_name} ({$booking->guest_email})")
            ->line("**Event:** {$booking->eventType->name}")
            ->line($startsAt->format('l, F j, Y \a\t g:i A T'));

        if (filled($booking->cancellation_reason)) {
            $mail->line("**Reason:** {$booking->cancellation_reason}");
        }

        return $mail->action('View bookings', route('bookings.index'));
    }
}
