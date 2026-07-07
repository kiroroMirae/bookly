<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Services\IcsGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuestBookingCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking;
        $startsAt = $booking->starts_at->setTimezone($booking->guest_timezone);

        $mail = (new MailMessage)
            ->subject("Booking cancelled: {$booking->eventType->name}")
            ->greeting("Hi {$booking->guest_name},")
            ->line('Your booking has been cancelled.')
            ->line("**{$booking->eventType->name}** with {$booking->host->name}")
            ->line($startsAt->format('l, F j, Y \a\t g:i A T'));

        if (filled($booking->cancellation_reason)) {
            $mail->line("**Reason:** {$booking->cancellation_reason}");
        }

        $ics = new IcsGenerator;

        return $mail->attachData($ics->forBooking($booking, 'CANCEL'), 'invite.ics', [
            'mime' => $ics->mimeType('CANCEL'),
        ]);
    }
}
