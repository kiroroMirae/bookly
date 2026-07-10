<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class IcsGenerator
{
    private const MAX_LINE_OCTETS = 75;

    /**
     * Build an RFC 5545 VCALENDAR for the booking.
     *
     * @param  'REQUEST'|'CANCEL'  $method
     */
    public function forBooking(Booking $booking, string $method = 'REQUEST'): string
    {
        $booking->loadMissing('eventType', 'host');

        $eventType = $booking->eventType;
        $host = $booking->host;
        $sequence = $booking->ics_sequence ?? 0;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Bookly//Bookly//EN',
            'CALSCALE:GREGORIAN',
            "METHOD:{$method}",
            'BEGIN:VEVENT',
            "UID:booking-{$booking->id}@bookly",
            "SEQUENCE:{$sequence}",
            'DTSTAMP:'.$this->utc(CarbonImmutable::now()),
            'DTSTART:'.$this->utc($booking->starts_at),
            'DTEND:'.$this->utc($booking->ends_at),
            'SUMMARY:'.$this->escape("{$eventType->name} with {$host->name}"),
        ];

        if (filled($eventType->description)) {
            $lines[] = 'DESCRIPTION:'.$this->escape($eventType->description);
        }

        if (filled($booking->location)) {
            $lines[] = 'LOCATION:'.$this->escape($booking->location);
        }

        $lines[] = 'ORGANIZER;CN='.$this->escape($host->name).':mailto:'.$host->email;
        $lines[] = 'ATTENDEE;CN='.$this->escape($booking->guest_name)
            .';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:'.$booking->guest_email;
        $lines[] = $method === 'CANCEL' ? 'STATUS:CANCELLED' : 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    public function mimeType(string $method = 'REQUEST'): string
    {
        return "text/calendar; charset=utf-8; method={$method}";
    }

    /**
     * Build a subscribable RFC 5545 PUBLISH calendar of the host's bookings.
     *
     * @param  Collection<int, Booking>  $bookings
     */
    public function forHostFeed(User $host, Collection $bookings): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Bookly//Bookly//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape("Bookly — {$host->name}"),
        ];

        foreach ($bookings as $booking) {
            $lines = [...$lines, ...$this->feedEventLines($booking)];
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    public function feedMimeType(): string
    {
        return 'text/calendar; charset=utf-8';
    }

    /**
     * VEVENT lines for a feed entry, written from the host's perspective.
     * Deliberately carries no ORGANIZER/ATTENDEE so calendar clients render
     * a plain event instead of an invitation.
     *
     * @return list<string>
     */
    private function feedEventLines(Booking $booking): array
    {
        $booking->loadMissing('eventType');

        $eventType = $booking->eventType;
        $sequence = $booking->ics_sequence ?? 0;

        $description = "Guest: {$booking->guest_name} ({$booking->guest_email})";

        if (filled($eventType->description)) {
            $description .= "\n{$eventType->description}";
        }

        return [
            'BEGIN:VEVENT',
            "UID:booking-{$booking->id}@bookly",
            "SEQUENCE:{$sequence}",
            'DTSTAMP:'.$this->utc(CarbonImmutable::now()),
            'DTSTART:'.$this->utc($booking->starts_at),
            'DTEND:'.$this->utc($booking->ends_at),
            'SUMMARY:'.$this->escape("{$eventType->name} with {$booking->guest_name}"),
            'DESCRIPTION:'.$this->escape($description),
            'STATUS:CONFIRMED',
            'END:VEVENT',
        ];
    }

    private function utc(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format('Ymd\THis\Z');
    }

    /**
     * Escape per RFC 5545 §3.3.11 — backslash first, then structural characters.
     */
    private function escape(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace([';', ','], ['\\;', '\\,'], $text);

        return str_replace(["\r\n", "\n", "\r"], '\\n', $text);
    }

    /**
     * Fold lines longer than 75 octets (continuation lines start with a space
     * and include it in their 75-octet budget), never splitting a UTF-8 sequence.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_LINE_OCTETS) {
            return $line;
        }

        $chunks = [];
        $limit = self::MAX_LINE_OCTETS;

        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $chunks[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $limit = self::MAX_LINE_OCTETS - 1;
        }
        $chunks[] = $line;

        return implode("\r\n ", $chunks);
    }
}
