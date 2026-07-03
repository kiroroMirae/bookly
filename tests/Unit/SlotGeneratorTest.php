<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Services\SlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeHost(string $timezone = 'UTC'): User
{
    return User::factory()->create(['timezone' => $timezone]);
}

function makeEventType(User $host, int $durationMinutes = 30): EventType
{
    return EventType::factory()->create([
        'user_id' => $host->id,
        'duration_minutes' => $durationMinutes,
    ]);
}

function addWindow(User $host, int $day, string $start, string $end): void
{
    AvailabilityWindow::factory()->create([
        'user_id' => $host->id,
        'day_of_week' => $day,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

// ── no availability ───────────────────────────────────────────────────────────

it('returns empty when host has no availability for that day', function () {
    $host = makeHost();
    $eventType = makeEventType($host);

    addWindow($host, 1, '09:00', '10:00'); // Monday only — request Tuesday
    CarbonImmutable::setTestNow('2025-01-05 00:00:00');

    $date = CarbonImmutable::parse('2025-01-07'); // Tuesday
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    expect($slots)->toBeEmpty();
})->afterEach(fn () => CarbonImmutable::setTestNow());

// ── basic slot stepping ───────────────────────────────────────────────────────

it('generates correct number of slots for a window', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00'); // Sunday

    // Monday = day 1, 09:00-11:00 → 4 half-hour slots: 9:00, 9:30, 10:00, 10:30
    addWindow($host, 1, '09:00', '11:00');

    $date = CarbonImmutable::parse('2025-01-06'); // Monday
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    expect($slots)->toHaveCount(4);
    expect($slots[0]['starts_at'])->toContain('2025-01-06T09:00:00');
    expect($slots[3]['starts_at'])->toContain('2025-01-06T10:30:00');
})->afterEach(fn () => CarbonImmutable::setTestNow());

// ── past slot exclusion ───────────────────────────────────────────────────────

it('excludes past slots', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);

    CarbonImmutable::setTestNow('2025-01-06 10:00:00'); // Monday 10:00 UTC

    addWindow($host, 1, '09:00', '12:00'); // 6 slots

    $date = CarbonImmutable::parse('2025-01-06');
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    // 9:00 and 9:30 start before 10:00 → excluded; 10:00, 10:30, 11:00, 11:30 remain
    expect($slots)->toHaveCount(4);
    expect($slots[0]['starts_at'])->toContain('T10:00:00');
})->afterEach(fn () => CarbonImmutable::setTestNow());

// ── confirmed booking exclusion ───────────────────────────────────────────────

it('excludes slots that overlap with confirmed bookings', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00');
    addWindow($host, 1, '09:00', '11:00'); // 4 slots

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 09:30:00',
        'ends_at' => '2025-01-06 10:00:00',
        'status' => BookingStatus::Confirmed,
    ]);

    $date = CarbonImmutable::parse('2025-01-06');
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    expect($slots)->toHaveCount(3);
    $startTimes = array_column($slots, 'starts_at');
    expect(implode(',', $startTimes))->not->toContain('T09:30:00');
})->afterEach(fn () => CarbonImmutable::setTestNow());

// ── cancelled booking does not block ─────────────────────────────────────────

it('does not exclude slots that overlap with cancelled bookings', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00');
    addWindow($host, 1, '09:00', '11:00'); // 4 slots

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 09:30:00',
        'ends_at' => '2025-01-06 10:00:00',
        'status' => BookingStatus::Cancelled,
    ]);

    $date = CarbonImmutable::parse('2025-01-06');
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    expect($slots)->toHaveCount(4);
})->afterEach(fn () => CarbonImmutable::setTestNow());

// ── timezone conversion ───────────────────────────────────────────────────────

it('converts display time to guest timezone', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 60);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00');
    addWindow($host, 1, '09:00', '10:00'); // 1 slot at 09:00 UTC

    $date = CarbonImmutable::parse('2025-01-06');
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'Asia/Kuala_Lumpur');

    // UTC 09:00 = MYT 17:00 (UTC+8)
    expect($slots)->toHaveCount(1);
    expect($slots[0]['display'])->toBe('5:00 PM');
})->afterEach(fn () => CarbonImmutable::setTestNow());

it('generates slots based on host timezone window', function () {
    $host = makeHost('America/New_York'); // UTC-5 in January
    $eventType = makeEventType($host, 60);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00');

    // Monday 09:00-11:00 New York = Monday 14:00-16:00 UTC
    addWindow($host, 1, '09:00', '11:00');

    $date = CarbonImmutable::parse('2025-01-06'); // Monday
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    expect($slots)->toHaveCount(2);
    expect($slots[0]['starts_at'])->toContain('T14:00:00');
    expect($slots[1]['starts_at'])->toContain('T15:00:00');
})->afterEach(fn () => CarbonImmutable::setTestNow());

// ── buffers ───────────────────────────────────────────────────────────────────

it('excludes slots that would fall within the buffer before an existing booking', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);
    $eventType->update(['buffer_before_minutes' => 15, 'buffer_after_minutes' => 0]);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00');
    addWindow($host, 1, '09:00', '11:00'); // 4 base slots

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 10:00:00',
        'ends_at' => '2025-01-06 10:30:00',
        'status' => BookingStatus::Confirmed,
    ]);

    $date = CarbonImmutable::parse('2025-01-06');
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    // 9:30 slot ends 10:00, but 15-min buffer before the 10:00 booking excludes it
    $startTimes = array_column($slots, 'starts_at');
    expect($startTimes)->not->toContain('2025-01-06T09:30:00+00:00');
})->afterEach(fn () => CarbonImmutable::setTestNow());

it('excludes slots that would fall within the buffer after an existing booking', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);
    $eventType->update(['buffer_before_minutes' => 0, 'buffer_after_minutes' => 15]);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00');
    addWindow($host, 1, '09:00', '11:00');

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Confirmed,
    ]);

    $date = CarbonImmutable::parse('2025-01-06');
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    // 9:30 slot starts right when the prior booking ends, but the 15-min buffer after excludes it
    $startTimes = array_column($slots, 'starts_at');
    expect($startTimes)->not->toContain('2025-01-06T09:30:00+00:00');
})->afterEach(fn () => CarbonImmutable::setTestNow());

// ── minimum notice ───────────────────────────────────────────────────────────

it('excludes slots inside the minimum notice window', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);
    $eventType->update(['minimum_notice_minutes' => 120]);

    CarbonImmutable::setTestNow('2025-01-06 09:00:00'); // Monday 09:00
    addWindow($host, 1, '09:00', '12:00'); // 6 base slots

    $date = CarbonImmutable::parse('2025-01-06');
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    // With 2h notice from 09:00, first bookable slot is 11:00
    expect($slots[0]['starts_at'])->toContain('T11:00:00');
})->afterEach(fn () => CarbonImmutable::setTestNow());

// ── booking window ────────────────────────────────────────────────────────────

it('returns no slots beyond the booking window', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);
    $eventType->update(['booking_window_days' => 5]);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00'); // Sunday
    addWindow($host, 1, '09:00', '10:00');

    // Next Monday is 8 days out — beyond the 5-day window
    $date = CarbonImmutable::parse('2025-01-13');
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    expect($slots)->toBeEmpty();
})->afterEach(fn () => CarbonImmutable::setTestNow());

it('returns slots within the booking window', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);
    $eventType->update(['booking_window_days' => 5]);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00'); // Sunday
    addWindow($host, 1, '09:00', '10:00');

    $date = CarbonImmutable::parse('2025-01-06'); // Monday, within window
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    expect($slots)->not->toBeEmpty();
})->afterEach(fn () => CarbonImmutable::setTestNow());

// ── daily booking cap ─────────────────────────────────────────────────────────

it('returns no slots once the daily booking cap is reached', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);
    $eventType->update(['max_bookings_per_day' => 1]);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00');
    addWindow($host, 1, '09:00', '11:00');

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Confirmed,
    ]);

    $date = CarbonImmutable::parse('2025-01-06');
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    expect($slots)->toBeEmpty();
})->afterEach(fn () => CarbonImmutable::setTestNow());

it('does not count cancelled bookings toward the daily cap', function () {
    $host = makeHost('UTC');
    $eventType = makeEventType($host, 30);
    $eventType->update(['max_bookings_per_day' => 1]);

    CarbonImmutable::setTestNow('2025-01-05 00:00:00');
    addWindow($host, 1, '09:00', '11:00');

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'host_user_id' => $host->id,
        'starts_at' => '2025-01-06 09:00:00',
        'ends_at' => '2025-01-06 09:30:00',
        'status' => BookingStatus::Cancelled,
    ]);

    $date = CarbonImmutable::parse('2025-01-06');
    $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

    expect($slots)->not->toBeEmpty();
})->afterEach(fn () => CarbonImmutable::setTestNow());
