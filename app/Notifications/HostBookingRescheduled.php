<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Services\IcsGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HostBookingRescheduled extends Notification implements ShouldQueue
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

        return (new MailMessage)
            ->subject("Booking rescheduled: {$booking->eventType->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line('A guest has rescheduled their booking.')
            ->line("**Guest:** {$booking->guest_name} ({$booking->guest_email})")
            ->line("**Event:** {$booking->eventType->name}")
            ->line("**New time:** {$startsAt->format('l, F j, Y \a\t g:i A T')}")
            ->action('View bookings', route('bookings.index'))
            ->attachData(($ics = new IcsGenerator)->forBooking($booking), 'invite.ics', [
                'mime' => $ics->mimeType(),
            ]);
    }
}
