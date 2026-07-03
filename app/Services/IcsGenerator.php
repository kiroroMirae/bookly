<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

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
