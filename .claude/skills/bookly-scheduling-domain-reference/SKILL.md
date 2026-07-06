---
name: bookly-scheduling-domain-reference
description: Domain reference for Bookly's scheduling core — slot generation (SlotGenerator), availability windows, availability overrides, timezone and DST handling, buffers, minimum notice, booking window, daily caps, and ICS / RFC 5545 calendar output (IcsGenerator, calendar feed). Load when working on slot generation, availability, timezone math, booking policies, reschedule slot checks, or .ics email attachments and the subscribable calendar feed.
---

# Bookly Scheduling Domain Reference

Verified against the codebase on 2026-07-05. Every claim below is anchored to code
or to a test that pins the behavior. Repo root: `C:\xampp\htdocs\bookly`.

## When NOT to use this skill

- Changing HOW to safely change scheduling code (process, review gates) → `bookly-change-control`.
- General layering/architecture questions → `bookly-architecture-contract`.
- Diagnosing a live bug step-by-step → `bookly-debugging-playbook`.
- Past incidents / known regressions → `bookly-failure-archaeology`.
- Planning new timezone/DST hardening work → `bookly-timezone-correctness-campaign`
  (that skill owns the campaign; this one owns the mechanics it references).
- Env setup, running the app, QA process → the build/run/validation siblings.

## Glossary (as defined by THIS codebase)

| Term | Meaning here |
|---|---|
| Window | Weekly recurring availability: `availability_windows` row (`day_of_week` 0=Sun…6=Sat, `start_time`/`end_time` as host-local `TIME`). |
| Override | Per-date exception: `availability_overrides` row. Both times NULL = full-day block; times set = replacement hours for that date. |
| Slot | A candidate bookable interval of exactly `duration_minutes`, stepped back-to-back inside a window (no separate "slot interval" concept). |
| Buffer | `buffer_before_minutes` / `buffer_after_minutes` on `event_types`. Pads EXISTING bookings during collision checks only — does not shrink windows or space free slots apart. |
| Notice | `minimum_notice_minutes` on `event_types`: earliest bookable instant = now + notice (UTC comparison). |
| Booking window | `booking_window_days` on `event_types` (default 60): last bookable calendar day = host-local today + N days. |
| Daily cap | `max_bookings_per_day` on `event_types` (nullable = unlimited): max non-cancelled bookings of THIS event type per host-local day. |

## 1. The data model of time

**App timezone is UTC** (`config/app.php:68`). Column semantics:

| Column | Stored as | Timezone | Source |
|---|---|---|---|
| `users.timezone` | string, default `'Asia/Kuala_Lumpur'` | IANA id, the host's zone | migration `2026_06_29_063547_add_username_timezone_to_users_table.php` |
| `availability_windows.start_time`/`end_time` | `TIME` (string, no cast) | **host-local wall clock** | migration `..._create_availability_windows_table.php`; interpreted in host tz at `SlotGenerator.php:79-86` |
| `availability_windows.day_of_week` | tinyint 0=Sun…6=Sat | host-local weekday | same migration (comment) + `SlotGenerator.php:18` (`format('w')`) |
| `availability_overrides.date` | `DATE`, cast `date` | host-local calendar date | `AvailabilityOverride.php:22-27`; matched via `whereDate` at `SlotGenerator.php:26-29` |
| `availability_overrides.start_time`/`end_time` | nullable `TIME` | host-local wall clock (NULL+NULL = full-day block, `AvailabilityOverride::isFullDayBlock()` line 29-32) | migration `2026_07_03_021834` (comment) |
| `bookings.starts_at`/`ends_at` | `dateTime`, cast `datetime` | **UTC** | migration `..._create_bookings_table.php` (`// UTC` comments); written from UTC-parsed input at `PublicBookingController.php:60` |
| `bookings.guest_timezone` | string | guest's IANA id, display-only | `PublicBookingController.php:84` |
| `bookings.ics_sequence` | unsigned int, default 0 | n/a — RFC 5545 SEQUENCE counter | migration `2026_07_03_023719` |

**Conversion happens in exactly one direction per concern:**

- Host-local wall clock → UTC: inside `SlotGenerator` when a window time is combined
  with the date (`SlotGenerator.php:79-86`, then `->utc()` at 97-98).
- UTC → guest-local: only for the `display` string of each slot
  (`SlotGenerator.php:109`) and in notification/confirmation rendering. The canonical
  `starts_at` in the slot payload is an ISO-8601 UTC string (`SlotGenerator.php:108`).
- **Guest timezone never affects WHICH slots exist — only how they are labeled.**
  Pinned by `tests/Unit/SlotGeneratorTest.php:142-155` ("converts display time to
  guest timezone": UTC 09:00 → `5:00 PM` for `Asia/Kuala_Lumpur`).

**How the guest timezone arrives:** `PublicBookingController::show()` reads `?tz=`
query param, validates against `timezone_identifiers_list()`, falls back to `'UTC'`
(`PublicBookingController.php:30-33`; pinned by `tests/Feature/PublicBookingTest.php:62,72`).
On store, `StoreBookingRequest` validates `guest_timezone` and the chosen `starts_at`
is parsed as UTC (`PublicBookingController.php:60`, rule `starts_at => required|date|after:now`
in `StoreBookingRequest.php:15`).

## 2. SlotGenerator pipeline

Single entry point: `SlotGenerator::forDate(EventType, CarbonImmutable $date, string $guestTimezone, ?int $ignoreBookingId = null): array`
— `app/Services/SlotGenerator.php:11`. Returns `list<array{starts_at: string, display: string}>`.
`$ignoreBookingId` exists for reschedule flows so the booking being moved doesn't block itself
(`GuestBookingController.php:94-96`, `BookingController.php:93-95`).

### Stage 0 — Normalize the date to the host's calendar (lines 13-18)

The incoming `$date` is reduced to its `Y-m-d` string and re-parsed **in the host's
timezone** (line 17) "to avoid cross-midnight shifts". Weekday is computed from that
host-local date (line 18, `0=Sun…6=Sat`).

### Stage 1 — Booking window gate (lines 20-24)

`windowEndDate = today(host tz) startOfDay + booking_window_days`. If the requested
host-local day is strictly AFTER that, return `[]`. Note: the boundary day itself
(`gt`, not `gte`) IS bookable. Pinned: `SlotGeneratorTest.php:243-270` (5-day window,
8 days out → empty; next day → slots). Exact-boundary-day equality is **untested**.

### Stage 2 — Override substitution / blocking (lines 26-45)

1. Load all overrides for that host-local date, ordered by `start_time`.
2. If ANY is a full-day block (both times null) → return `[]`
   (pinned: `SlotGeneratorTest.php:330-342`).
3. Else if any timed overrides exist, they **replace** the weekly windows for this
   date entirely (pinned: `:344-358` — weekly 09-17 + override 13-15 → exactly 4 slots).
   A timed override also OPENS a day that has no weekly window (pinned: `:374-387`).
4. Otherwise fall back to weekly `availability_windows` matching `day_of_week`.
5. No windows at all → `[]` (pinned: `:42-53`).

Overrides on other dates never leak (pinned: `:360-372`). HTTP-level invariants
enforced in `AvailabilityOverrideTest.php`: no past dates, end > start, start requires
end, cannot mix a full-day block with custom hours on the same date.

### Stage 3 — Load competing bookings (lines 47-55)

Day boundaries: host-local `startOfDay`/`endOfDay` converted to UTC (lines 47-48).
Fetch ALL of the **host's** bookings (any event type) where `status != cancelled`,
excluding `$ignoreBookingId`, that overlap the day (`starts_at < dayEnd AND ends_at > dayStart`).
Cancelled bookings never block (pinned: `SlotGeneratorTest.php:119-138`).

### Stage 4 — Daily cap gate (lines 57-68)

Only if `max_bookings_per_day` is non-null. Counts non-cancelled bookings **of this
event type only** overlapping the same UTC day-range; if count ≥ cap, the whole day
returns `[]` (pinned: `:274-294`; cancelled excluded from count: `:296-316`).
Note the asymmetry: collision uses all host bookings; the cap counts only this event
type. `Completed`/`NoShow` statuses count toward the cap (only `Cancelled` is
excluded — `BookingStatus` enum has 4 cases).

### Stage 5 — Slot emission loop (lines 70-118)

For each window, in order:

1. Build `windowStart`/`windowEnd` by parsing `"{Y-m-d} {start_time}"` in the host
   timezone (lines 79-86). **This is where DST resolution happens implicitly via Carbon.**
2. Step candidate slots back-to-back: `slotStart = windowStart`, `slotEnd = slotStart + duration_minutes`;
   emit only while `slotEnd <= windowEnd` (line 93 — a partial trailing remainder is dropped);
   advance `slotStart = slotEnd` (line 114). There is no rounding to :00/:30 — slots
   inherit the window's start minute.
3. **Minimum notice / past filter** (lines 73-74, 100): slot kept only if
   `slotStartUtc >= now(UTC) + minimum_notice_minutes`. Pinned: past-slot exclusion
   `:76-90`; 120-min notice `:226-239`.
4. **Collision with buffers** (lines 101-104): a slot is blocked when
   `booking.starts_at - buffer_before < slotEnd` AND `booking.ends_at + buffer_after > slotStart`
   (strict inequalities → with zero buffers, back-to-back adjacency is allowed;
   implicitly pinned by `:94-115` where slots at 09:00 and 10:00 survive around a
   09:30-10:00 booking). Buffer-before pinned `:176-198`, buffer-after `:200-222`.
   Blocked slots are skipped but stepping continues — the next candidate still starts
   at the ORIGINAL grid position, it is not pushed past the booking.
5. Emit `['starts_at' => ISO-8601 UTC, 'display' => guest-local 'g:i A']` (lines 107-110).

### Worked example A — the happy path with an offset zone

Host in `Asia/Singapore` (UTC+8), weekly window Mon `09:00`–`12:00`, 30-min event,
no policies set. Request Monday 2025-01-06, guest tz `America/New_York` (UTC-5):

- Window = 2025-01-06 09:00–12:00 SGT = 01:00–04:00 UTC.
- 6 slots stepped at 30-min: `01:00, 01:30, 02:00, 02:30, 03:00, 03:30` UTC.
- Guest sees displays `8:00 PM … 10:30 PM` — **Sunday Jan 5 in New York**. The
  payload carries no guest-local DATE, only a time string (`SlotGenerator.php:109`);
  the UI groups slots under the HOST-local `selectedDate`
  (`PublicBookingController.php:46`). This date-rollover display is a known sharp edge.

### Worked example B — buffers around an existing booking

Host UTC, window Mon 09:00–11:00, 30-min event, `buffer_before=15`, existing
confirmed booking 10:00–10:30:

- Candidates: 09:00, 09:30, 10:00, 10:30.
- 09:30 slot ends 10:00; booking padded start = 09:45 < 10:00 → **blocked**.
- 09:00 slot ends 09:30 ≤ 09:45 → kept. 10:00 collides with the booking itself → blocked.
- 10:30 kept (buffer_after=0). Result: 09:00, 10:30.
  (Matches `SlotGeneratorTest.php:176-198`.)

### Worked example C — non-UTC host window in UTC terms

Host `America/New_York` (UTC-5 in January), window Mon 09:00–11:00, 60-min event →
exactly 2 slots at `14:00` and `15:00` UTC (pinned verbatim: `SlotGeneratorTest.php:157-172`).

## 3. Double-booking defense (the locking read)

Slot availability shown on the page is advisory. The authoritative check happens at
write time inside a DB transaction in three places, all with the same pattern:

1. `DB::transaction`, then `$host->bookings()->lockForUpdate()->get()` — a pessimistic
   lock over the host's booking rows (`PublicBookingController.php:63-66`,
   `GuestBookingController.php:~90`, `BookingController.php:~89`).
2. Re-run `SlotGenerator::forDate()` inside the transaction (reschedules pass the
   booking's own id as `$ignoreBookingId`).
3. Require the requested `starts_at` to exactly equal an open slot's `starts_at`,
   else `abort(422, 'That time slot is no longer available.')`.
4. `ends_at` is always derived server-side as `starts_at + duration_minutes` — the
   client never supplies it. Reschedules also reset `reminder_sent_at` and bump
   `ics_sequence` (`GuestBookingController.php:110`, `BookingController.php:109`).

Note: the date used for the in-transaction recheck is parsed from the UTC `starts_at`
(`PublicBookingController.php:68`) and then re-normalized to the host calendar inside
`forDate` — a UTC instant near host-midnight lands on the correct host-local day via
`SlotGenerator.php:17` only if the UTC date string matches the host-local date; see
untested edge #2 below.

## 4. ICS generation (`app/Services/IcsGenerator.php`)

Two products, both RFC 5545, both CRLF-terminated and folded at 75 octets without
splitting UTF-8 sequences (`fold()`, lines 146-167; pinned `IcsGeneratorTest.php:146-159,239-249`).

### 4a. Per-booking invite: `forBooking(Booking, $method = 'REQUEST')` (lines 20-55)

| Field | Value | Anchor |
|---|---|---|
| `METHOD` | `REQUEST` (confirm/reschedule) or `CANCEL` | line 33 |
| `UID` | `booking-{id}@bookly` — stable across confirm AND cancel | line 35; pinned test `:69-75` |
| `SEQUENCE` | `bookings.ics_sequence` (bumped +1 on every reschedule/cancel path in controllers) | line 36 |
| `DTSTART`/`DTEND`/`DTSTAMP` | UTC `Ymd\THis\Z` form | lines 37-39, `utc()` 126-129; pinned `:77-85` |
| `SUMMARY` | `"{event name} with {host name}"`, RFC-escaped | line 40 |
| `DESCRIPTION` | event type description, omitted when blank | lines 43-45; pinned `:140-144` |
| `ORGANIZER` | host (`CN=` + mailto) | line 47 |
| `ATTENDEE` | guest, `ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED` | lines 48-49 |
| `STATUS` | `CANCELLED` when method=CANCEL else `CONFIRMED` | line 50; pinned `:100-106` |

Escaping order matters: backslash first, then `;`/`,`, then newlines → `\n`
(`escape()` lines 134-140, per RFC 5545 §3.3.11; pinned `:128-138`).
MIME type carries the method: `text/calendar; charset=utf-8; method=REQUEST|CANCEL`
(`mimeType()` lines 57-60).

**Where invites are attached** (all as `invite.ics` via `attachData`):

- `GuestBookingConfirmed` + `HostNewBooking` — METHOD:REQUEST on create.
- `GuestBookingRescheduled` + `HostBookingRescheduled` — METHOD:REQUEST with bumped SEQUENCE.
- `GuestBookingCancelled` + `HostBookingCancelledByGuest` — METHOD:CANCEL.
- `GuestBookingReminder` — **no ICS attachment**.

### 4b. Subscribable host feed: `forHostFeed(User, Collection<Booking>)` (lines 67-124)

`METHOD:PUBLISH`, `X-WR-CALNAME: Bookly — {host name}`, one VEVENT per booking with
the same stable UID/SEQUENCE, SUMMARY from the host's perspective
(`"{event} with {guest name}"`), guest name+email in DESCRIPTION, always
`STATUS:CONFIRMED`, and **deliberately no ORGANIZER/ATTENDEE** so clients render a
plain event, not an invitation (doc comment lines 92-97; pinned `:230-237`).

Served by `CalendarFeedController::show(string $token)` — unauthenticated by design,
`users.calendar_feed_token` (64-char random) is the secret; includes Confirmed +
Completed + NoShow (not Cancelled) bookings from the last 90 days onward
(`HISTORY_DAYS`), `Cache-Control: private, max-age=300`. Token rotation:
`regenerate()` (`CalendarFeedController.php:25-52`).

## 5. Edge cases already pinned by tests (executable spec)

From `tests/Unit/SlotGeneratorTest.php` unless noted:

- No window for the weekday → empty (`:42`).
- Slot count = floor(window / duration), back-to-back (`:57`).
- Past slots excluded relative to test-now (`:76`).
- Confirmed booking blocks its slot; cancelled does not (`:94`, `:119`).
- Guest-tz display conversion; host-tz window→UTC conversion (`:142`, `:157`).
- buffer_before and buffer_after each independently block the adjacent slot (`:176`, `:200`).
- Minimum notice pushes the first bookable slot forward (`:226`).
- Booking window: beyond → empty; within → slots (`:243`, `:258`).
- Daily cap reached → whole day empty; cancelled don't count (`:274`, `:296`).
- Overrides: full-day block; timed replacement; other-date isolation; override opens
  a windowless day (`:330-387`).
- Invalid `?tz=` and `?date=` fall back safely (`PublicBookingTest.php:62,81`).
- HTTP validation: `day_of_week` 0-6, end>start, no overlapping windows per day
  (`AvailabilityTest.php`); override date not past, block-vs-hours mutual exclusion
  (`AvailabilityOverrideTest.php`).
- Full ICS shape: ordering, CRLF, UID stability, UTC Z times, SEQUENCE, CANCEL
  variant, escaping, folding, feed shape (`IcsGeneratorTest.php`, entire file).

## 6. Edge cases NOT covered by tests — untested, behavior unverified

Feed these to `bookly-timezone-correctness-campaign`; do not assume behavior without
writing a pinning test first.

1. **DST spring-forward**: a window time that does not exist on transition day (e.g.
   `America/New_York` 2026-03-08, window 02:00-03:00). Carbon will resolve the parse
   somehow at `SlotGenerator.php:79-86`, but nothing pins whether slots appear,
   shift, or duplicate. Same for fall-back ambiguous times (01:30 occurs twice).
2. **Host-vs-request date skew**: `forDate` re-parses only the `Y-m-d` of the incoming
   date in host tz (line 17). Callers build that date from a UTC `starts_at`
   (`PublicBookingController.php:68`) — for hosts far from UTC, a slot near host
   midnight can have a UTC calendar date differing from the host-local date, making
   the in-transaction recheck look at the wrong day. No test exercises this.
3. **Guest-local date rollover in display**: slots render only a time string; a guest
   west of the host can see times belonging to a different guest-local date (worked
   example A). Untested and un-UI-verified.
4. **Booking-window boundary equality**: day exactly `today + booking_window_days`
   is allowed by code (`gt`, line 22) but no test pins it.
5. **Bookings crossing host-midnight**: the day-overlap query (lines 53-54, 61-62)
   would count such a booking toward the daily cap on BOTH days and block slots on
   both. No test creates a midnight-crossing booking.
6. **Windows/overrides crossing midnight** (`end_time < start_time`): code emits zero
   slots (first `slotEnd > windowEnd` breaks immediately, line 93); HTTP validation
   rejects it, but direct DB rows are unpinned.
7. **Overlapping windows at generator level**: HTTP rejects overlaps, but if two
   overlapping rows exist in DB, `SlotGenerator` would emit duplicate/overlapping
   slots (no dedup in the loop). Untested.
8. **Multiple timed overrides on one date**: loaded and iterated in `start_time`
   order, but no test covers more than one timed override per date.
9. **Duration not dividing the window evenly** (e.g. 45-min event in a 2-hour
   window): remainder-drop at line 93 is untested.
10. **Daily cap vs. other statuses**: `Completed`/`NoShow` counting toward the cap is
    implied by `whereNot cancelled` but not pinned.
11. **Half-hour offset host timezones** (e.g. `Asia/Kathmandu` +05:45): no test.
12. **Feed window semantics**: `starts_at >= now - 90 days` has no upper bound —
    all future bookings included; not pinned by `CalendarFeedTest` for boundary dates
    (verify before relying).
13. **`ics_sequence` bump on host-initiated cancel**: guest-cancel and reschedule
    paths bump it; whether every cancel path does is unverified here — check
    `BookingController` cancel action before claiming.

## Provenance and maintenance

- Written 2026-07-05 against the working tree of `C:\xampp\htdocs\bookly` (no version
  tag available at authoring time).
- Primary sources: `app/Services/SlotGenerator.php` (121 lines, read in full),
  `app/Services/IcsGenerator.php` (169 lines, read in full),
  `app/Models/{AvailabilityWindow,AvailabilityOverride,EventType,Booking}.php`,
  `app/Http/Controllers/{PublicBookingController,GuestBookingController,BookingController,CalendarFeedController}.php`,
  migrations `2026_06_29_*` / `2026_07_02_*` / `2026_07_03_*`,
  `tests/Unit/{SlotGeneratorTest,IcsGeneratorTest}.php`,
  `tests/Feature/{AvailabilityTest,AvailabilityOverrideTest,PublicBookingTest}.php`.
- Line anchors will drift; when `SlotGenerator.php` or `IcsGenerator.php` changes,
  re-verify sections 2 and 4 and re-run
  `php artisan test --compact --filter=SlotGenerator` /
  `--filter=IcsGenerator` before trusting this document.
- When an item in section 6 gains a pinning test, move it to section 5 and note the
  test anchor. The timezone campaign skill owns the remediation backlog; this skill
  only records verified mechanics.
