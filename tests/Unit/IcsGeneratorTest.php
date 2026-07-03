<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Services\IcsGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeIcsBooking(array $eventTypeAttributes = [], array $bookingAttributes = []): Booking
{
    $host = User::factory()->create(['name' => 'Ann Host', 'email' => 'ann@example.com']);
    $eventType = EventType::factory()->create(array_merge([
        'user_id' => $host->id,
        'name' => 'Intro Call',
        'description' => 'A quick chat.',
        'duration_minutes' => 30,
    ], $eventTypeAttributes));

    return Booking::factory()->create(array_merge([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'guest_name' => 'Bob Guest',
        'guest_email' => 'bob@example.com',
        'starts_at' => '2026-07-10 14:00:00',
        'ends_at' => '2026-07-10 14:30:00',
    ], $bookingAttributes));
}

// ── structure ─────────────────────────────────────────────────────────────────

it('produces a well-ordered VCALENDAR with REQUEST defaults', function () {
    $ics = (new IcsGenerator)->forBooking(makeIcsBooking());

    $expectedOrder = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Bookly//Bookly//EN',
        'METHOD:REQUEST',
        'BEGIN:VEVENT',
        'STATUS:CONFIRMED',
        'END:VEVENT',
        'END:VCALENDAR',
    ];

    $lastPosition = -1;
    foreach ($expectedOrder as $token) {
        $position = strpos($ics, $token);
        expect($position)->not->toBeFalse("missing {$token}")
            ->and($position)->toBeGreaterThan($lastPosition);
        $lastPosition = $position;
    }
});

it('uses CRLF line endings exclusively', function () {
    $ics = (new IcsGenerator)->forBooking(makeIcsBooking());

    expect($ics)->toContain("\r\n")
        ->and(preg_match('/(?<!\r)\n/', $ics))->toBe(0);
});

// ── identity and times ────────────────────────────────────────────────────────

it('generates a stable UID from the booking id', function () {
    $booking = makeIcsBooking();
    $generator = new IcsGenerator;

    expect($generator->forBooking($booking))->toContain("UID:booking-{$booking->id}@bookly")
        ->and($generator->forBooking($booking, 'CANCEL'))->toContain("UID:booking-{$booking->id}@bookly");
});

it('renders DTSTART, DTEND and DTSTAMP in UTC Z form', function () {
    CarbonImmutable::setTestNow('2026-07-01 08:15:00');

    $ics = (new IcsGenerator)->forBooking(makeIcsBooking());

    expect($ics)->toContain('DTSTART:20260710T140000Z')
        ->and($ics)->toContain('DTEND:20260710T143000Z')
        ->and($ics)->toContain('DTSTAMP:20260701T081500Z');
})->afterEach(fn () => CarbonImmutable::setTestNow());

it('reflects the booking ics_sequence', function () {
    $generator = new IcsGenerator;
    $booking = makeIcsBooking();

    expect($generator->forBooking($booking))->toContain('SEQUENCE:0');

    $booking->update(['ics_sequence' => 2]);

    expect($generator->forBooking($booking->fresh()))->toContain('SEQUENCE:2');
});

// ── method variants ───────────────────────────────────────────────────────────

it('renders a cancellation with METHOD:CANCEL and STATUS:CANCELLED', function () {
    $ics = (new IcsGenerator)->forBooking(makeIcsBooking(), 'CANCEL');

    expect($ics)->toContain('METHOD:CANCEL')
        ->and($ics)->toContain('STATUS:CANCELLED')
        ->and($ics)->not->toContain('STATUS:CONFIRMED');
});

it('returns a text/calendar mime type carrying the method', function () {
    $generator = new IcsGenerator;

    expect($generator->mimeType())->toBe('text/calendar; charset=utf-8; method=REQUEST')
        ->and($generator->mimeType('CANCEL'))->toBe('text/calendar; charset=utf-8; method=CANCEL');
});

// ── people ────────────────────────────────────────────────────────────────────

it('lists the host as organizer and the guest as attendee', function () {
    $ics = (new IcsGenerator)->forBooking(makeIcsBooking());

    $unfolded = str_replace("\r\n ", '', $ics);

    expect($unfolded)->toContain('ORGANIZER;CN=Ann Host:mailto:ann@example.com')
        ->and($unfolded)->toContain('ATTENDEE;CN=Bob Guest;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:bob@example.com');
});

// ── text rules ────────────────────────────────────────────────────────────────

it('escapes backslashes, semicolons, commas and newlines in text values', function () {
    $booking = makeIcsBooking([
        'name' => 'Sales; Demo, Q&A\\Intro',
        'description' => "Line one\nLine two",
    ]);

    $unfolded = str_replace("\r\n ", '', (new IcsGenerator)->forBooking($booking));

    expect($unfolded)->toContain('SUMMARY:Sales\\; Demo\\, Q&A\\\\Intro with Ann Host')
        ->and($unfolded)->toContain('DESCRIPTION:Line one\\nLine two');
});

it('omits DESCRIPTION when the event type has none', function () {
    $ics = (new IcsGenerator)->forBooking(makeIcsBooking(['description' => null]));

    expect($ics)->not->toContain('DESCRIPTION');
});

it('folds long lines at 75 octets without splitting multibyte characters', function () {
    $description = str_repeat('éé ordé ', 30); // multibyte, > 75 octets

    $ics = (new IcsGenerator)->forBooking(makeIcsBooking(['description' => $description]));

    foreach (explode("\r\n", $ics) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    expect(mb_check_encoding($ics, 'UTF-8'))->toBeTrue();

    $unfolded = str_replace("\r\n ", '', $ics);
    expect($unfolded)->toContain('DESCRIPTION:'.trim($description));
});
