---
name: bookly-timezone-correctness-campaign
description: >
  Executable, phased remediation campaign for the 13 untested timezone/DST/edge-case
  items catalogued in bookly-scheduling-domain-reference section 6 (SlotGenerator
  and related timezone math). Use when the user says "harden timezone handling",
  "fix a DST bug", "work on the timezone campaign", "audit timezone edge cases", or
  asks to systematically pin down SlotGenerator's untested time-zone behavior. Does
  NOT trigger for routine availability/booking feature work — adding a window, fixing
  an unrelated UI bug, or a new event-type field should NOT pull this campaign in;
  see "When NOT to use this skill" below.
---

# Bookly Timezone Correctness Campaign

Written 2026-07-06 against `C:\xampp\htdocs\bookly` @ commit `086f30b`. This is a
**campaign skill**: it does not teach timezone concepts, it tracks and sequences the
retirement of a specific, named backlog of 13 unverified behaviors already catalogued
in `bookly-scheduling-domain-reference` section 6 ("Edge cases NOT covered by tests").

**Read `bookly-scheduling-domain-reference` section 6 in full before doing anything
here.** That section IS the backlog. This skill does not restate the domain mechanics
(window resolution, buffers, overrides, daily caps) — it only sequences how to attack
the untested edges. If you need to understand how `SlotGenerator` works at all, go
read that skill first.

## When NOT to use this skill

This is a fenced, campaign-scale activity. Do **not** load or invoke it for:

- Adding a new availability window, override, or event-type field (routine feature
  work — just follow `bookly-change-control`'s "New feature phase" gate).
- Fixing an unrelated UI bug, styling issue, or a controller/policy change that
  happens to touch a file near `SlotGenerator.php` but doesn't change time math.
- Routine "how does slot generation work" or "why did this slot appear/not appear"
  questions — that's `bookly-scheduling-domain-reference` (sections 1-5, the verified
  mechanics), not this campaign.
- Live debugging of a reported bug happening right now — use
  `bookly-debugging-playbook` first; only fold the finding into this campaign's
  backlog afterward if it turns out to be one of the 13 items (or a new one).
- Any single, isolated one-line Carbon fix a user explicitly asks for outside the
  scope of this backlog — do that fix directly, don't spin up campaign machinery for it.

If unsure whether a task is "campaign scale," ask: does it require writing a pinning
test against one of the 13 named items in domain-reference section 6, or does it
touch `SlotGenerator`'s time-conversion logic in a way that could invalidate one of
those items? If no to both, this skill does not apply.

## Campaign goal

Every one of the 13 items in `bookly-scheduling-domain-reference` section 6 gets
**exactly one** of two outcomes, each backed by a new Pest test:

1. **Pin as correct**: write a test that exercises the exact scenario and asserts
   today's actual behavior. If it matches reasonable/acceptable product behavior,
   the test passes as written — the edge case is now an executable spec, not a
   guess. Move the item from domain-reference section 6 to section 5 (update that
   skill) with the new test's anchor.
2. **Pin as a bug, then fix under gates**: if the test reveals genuinely wrong
   behavior (e.g. silently dropping bookable slots that should exist, or double-
   counting a booking against a daily cap on two calendar days at once), write the
   test to assert CORRECT behavior (it will fail red), then fix `SlotGenerator.php`
   (or the relevant model/controller) to make it pass — but only after the
   change-control gate for the affected area clears. `SlotGenerator.php` itself is
   not the `bookings`/`event_types` schema, so most fixes here are logic-only and
   don't need the schema-change human-approval gate — but if a fix requires a new
   or changed column on `bookings`/`event_types` (e.g. to store a resolved absolute
   instant instead of re-deriving it), STOP and get explicit human approval per
   `bookly-change-control` §5 before writing that migration.

**Never silently "fix" a discovered discrepancy without first landing the pinning
test and stating your classification (correct vs. bug) explicitly.** The whole point
of this campaign is to convert "nothing pins this" into an auditable trail.

## Environment fact needed before you start

Carbon version in use: **`nesbot/carbon` 3.13.0** (`composer.lock`, verified
2026-07-06 — search `"name": "nesbot/carbon"` if this drifts). DST/ambiguous-time
resolution behavior in this campaign is being tested against Carbon 3.13 specifically;
if `composer.lock` shows a different version when you pick this up, re-verify Phase 1
findings — Carbon's DST-gap/fold resolution has changed across major versions.
App timezone is hardcoded UTC (`config/app.php:68`, no `APP_TIMEZONE` env key — see
`bookly-build-and-env`), so `CarbonImmutable::now('UTC')` calls in `SlotGenerator.php`
are unaffected by DST; only host-timezone parsing (`SlotGenerator.php:79-86`) and the
host-tz `now()` call (line 20) are exposed to DST rules.

## Phased plan

Ordered by blast radius: items that can silently produce **wrong bookable UTC
instants** (guests could book, or fail to book, times that should behave differently)
come first; display-only and peripheral-subsystem items come last.

### Phase 1 — Silent wrong-instant risk (highest priority)

**1a. DST spring-forward / fall-back** (domain-reference item 1)

- Scenario A (gap): host tz `America/New_York`, weekly window Sunday `02:00`-`03:00`
  (or any window straddling 2026-03-08 02:00, the 2026 US spring-forward transition —
  clocks jump 02:00→03:00). `SlotGenerator.php:79-86` parses
  `"2026-03-08 02:00"` in `America/New_York` — that wall-clock time does not exist.
  Assert what Carbon 3.13 actually resolves this to (commonly it shifts forward by the
  gap, i.e. treats it as 03:00) and whether `windowEnd` shifts identically (if both
  shift together, slot count may still be internally consistent even though the
  wall-clock label is surprising — that distinction matters for your verdict).
- Scenario B (fold): same host tz, window straddling 2026-11-01 01:00-02:00 (the 2026
  US fall-back — 01:00-02:00 occurs twice). Assert which occurrence Carbon resolves
  to, and whether slots silently duplicate (two windows worth of slots at the same
  displayed guest time) or collapse to one.
- **Test file**: `tests/Unit/SlotGeneratorTest.php`, new `describe` block or grouped
  `it(...)` tests near the existing timezone-conversion tests (`:142-172`). Follow the
  existing pattern exactly: `makeHost('America/New_York')`, `addWindow(...)`,
  `CarbonImmutable::setTestNow(...)`, then assert on `$slots[n]['starts_at']` UTC
  strings (see the assertion style at `:170-171`).
- **Decision gate**: if slots resolve to a single sane instant per window with no
  duplication and no silently-missing hour → pin as correct, note the exact resolved
  offset in the test comment. If slots duplicate (fold) or silently vanish (gap) →
  classify as a bug, report the finding (this is `SlotGenerator.php` logic, not
  schema — no extra approval needed to fix the loop itself), then decide a remediation
  (e.g. skip windows that fall entirely inside a DST gap; dedupe by UTC instant for
  folds) and implement under the standard change-control workflow (§3 tests-first).

**1b. Host-vs-request date skew** (domain-reference item 2)

- Scenario: host tz far from UTC on the negative side, e.g. `Pacific/Midway`
  (UTC-11) or `America/Los_Angeles` (UTC-8). A UTC `starts_at` near host-local
  midnight (e.g. `2026-03-09 06:00:00 UTC` = `2026-03-08 22:00` Los Angeles) is passed
  into `forDate()` as `$date`. Confirm `PublicBookingController.php:68` (or wherever
  the in-transaction recheck derives its date argument) actually passes a UTC-derived
  `CarbonImmutable`, then trace whether `SlotGenerator.php:17`
  (`CarbonImmutable::parse($date->format('Y-m-d'), $hostTimezone)`) reduces it to the
  UTC calendar date or the host-local calendar date. `format('Y-m-d')` on a UTC
  instant yields the UTC date, not host-local — this is the suspected bug.
- **Test file**: `tests/Unit/SlotGeneratorTest.php`, new test constructing `$date` as
  `CarbonImmutable::parse('2026-03-09 06:00:00', 'UTC')` (a UTC instant that is still
  "yesterday" in `Pacific/Midway`), host tz `Pacific/Midway`, then asserting which
  weekday's window is actually matched (compare `$dayOfWeek` implicitly via which
  `addWindow(...)` day produces slots).
- **Decision gate**: if the wrong host-local day's window is matched → this is a real
  bug (a slot re-check during a transaction could look at the wrong day, per domain-
  reference item 2 and the note in section 3 "Double-booking defense"). Report before
  fixing. A fix likely means callers must pass the host-local date explicitly instead
  of relying on `forDate()` to re-derive it from a UTC instant's raw `Y-m-d` — that
  touches `PublicBookingController`, `GuestBookingController`, `BookingController`
  call sites, not schema, so no extra approval gate beyond normal change-control.

### Phase 2 — Window/booking integrity edge cases (moderate priority)

**2a. Bookings crossing host-midnight** (item 5): create a `Booking` with
`starts_at`/`ends_at` straddling host-local midnight (e.g. host UTC, booking
23:00-23:59 to 00:00+1 — construct via a host in a non-UTC zone so the UTC row
genuinely spans two host-local calendar days). Call `forDate()` for both affected
host-local days and assert whether the booking blocks slots / counts toward the daily
cap on both. Test file: `SlotGeneratorTest.php`, near the daily-cap tests (`:274-316`).
Decision gate: if it double-blocks/double-counts, decide whether that's acceptable
(defensive over-blocking, arguably safe) or a real cap-inflation bug worth fixing —
this is a product judgment call, not just a technical one; state your reasoning
explicitly in the pinning test's comment either way.

**2b. Overlapping windows at generator level** (item 7): insert two overlapping
`AvailabilityWindow` rows directly via the factory (bypassing HTTP validation, which
normally rejects this) for the same `day_of_week`. Assert whether `SlotGenerator`
emits duplicate/overlapping slot entries. Decision gate: if duplicates appear, this is
low severity (HTTP already prevents the data state) — pin as "known, low-priority,
accepted" rather than fixing, unless a migration-level unique/exclusion constraint is
trivial and approved separately.

**2c. Windows/overrides crossing midnight** (item 6, `end_time < start_time`): insert
a window/override row with `end_time` earlier than `start_time` directly via factory.
Confirm the documented "zero slots, loop breaks immediately" behavior with an actual
test rather than reading the code. Likely pin-as-correct (HTTP already rejects this
shape; DB-level defense-in-depth of "just emit nothing" is acceptable) — but write the
test before asserting that.

**2d. Multiple timed overrides on one date** (item 8): two `AvailabilityOverride` rows
same date, non-overlapping times (e.g. 09:00-10:00 and 14:00-15:00). Assert both
produce slots, in `start_time` order, per `SlotGenerator.php:36-41`'s `orderBy`. Likely
pin-as-correct; still needs an executable test since none exists today.

**2e. Duration not dividing the window evenly** (item 9): 45-minute event in a 2-hour
(120-minute) window → expect 2 full slots (0:00-0:45, 0:45-1:30) with a dropped
22.5-... wait, 120/45 = 2 slots + 30 min remainder dropped. Assert the remainder is
silently dropped per `SlotGenerator.php:93`, not rounded or error-raised. Pin as
correct (documented, intentional design per domain-reference — this is describing
existing behavior, not a bug) once verified by test.

### Phase 3 — Display and boundary correctness (lower priority, no write-path risk)

**3a. Guest-local date rollover in display** (item 3): this is a UI/UX sharp edge, not
a wrong-instant bug — the underlying `starts_at` is correct UTC; only the grouping-by-
host-date-with-guest-local-time DISPLAY can look confusing to guests near midnight
boundaries. Write a `SlotGenerator`-level test pinning the existing display string
behavior (worked example A in domain-reference is a good template: `Asia/Singapore`
host, `America/New_York` guest, verify displayed times land on what the UI still
labels as the host's date). Do not attempt a UI fix without an explicit product
decision — this may be intentional given `starts_at` is unambiguous UTC underneath.

**3b. Booking-window boundary equality** (item 4): request exactly
`today(host tz) + booking_window_days` as `$date`; assert it IS bookable (per the
`gt` not `gte` comparison at `SlotGenerator.php:22`). Straightforward pin — write the
test, confirm the documented `gt` semantics, done. Test file: near the existing
booking-window tests (`SlotGeneratorTest.php:243-270`).

**3c. Half-hour/45-minute offset host timezones** (item 11): host tz
`Asia/Kathmandu` (UTC+05:45). Verify window→UTC conversion produces correct
non-round-minute UTC instants (e.g. a 09:00-10:00 Kathmandu window should convert to
03:15-04:15 UTC). Pin as correct once verified — this is exercising Carbon's general
offset handling, unlikely to be broken, but currently has zero coverage.

**3d. Daily cap vs. other statuses** (item 10): create a `Completed` and a `NoShow`
booking (not `Confirmed`) and assert both count toward `max_bookings_per_day`, per the
`whereNot(status, Cancelled)` clause at `SlotGenerator.php:59`. Straightforward pin
near the existing daily-cap tests.

### Phase 4 — Peripheral subsystems (lowest priority, different files)

**4a. Feed window semantics** (item 12, `CalendarFeedController`): confirm there is
truly no upper bound on future bookings included in the ICS feed — read
`CalendarFeedController.php` and add a `CalendarFeedTest` covering a booking far in
the future (e.g. `booking_window_days` + 100 days out, if that's even reachable) to
pin the unbounded-forward-window behavior explicitly.

**4b. `ics_sequence` bump on host-initiated cancel** (item 13): before writing a test,
first READ `app/Http/Controllers/BookingController.php`'s cancel action to check
whether it bumps `ics_sequence` (domain-reference flags this as genuinely unverified,
not just untested). If it doesn't bump but guest-cancel does, that's an inconsistency
— pin whichever behavior is more correct per RFC 5545 (SEQUENCE must increase on any
change sent to attendees) and decide whether to fix the host path to match.

## Fencing recap (do not scope-creep)

- This campaign only concerns the 13 named items (or their direct descendants
  discovered while testing them). If you find an unrelated bug while working a phase,
  report it separately — do not fold it into this backlog without updating
  `bookly-scheduling-domain-reference` section 6 first to add it as a new numbered item.
- Do not batch-rewrite `SlotGenerator.php` for style or performance while doing this
  work. Each phase item gets its own minimal, targeted test + (if needed) fix — not a
  rearchitecture.
- Do not touch `bookings`/`event_types` schema without the human approval called out
  under "Campaign goal" above.
- After each item is pinned, update `bookly-scheduling-domain-reference` section 5/6
  (move the item, add the test anchor) so the backlog stays truthful. That skill's
  "Provenance and maintenance" section already says section 6 items migrate to
  section 5 once pinned — this campaign is the mechanism that makes that happen.

## Concrete first step for a fresh session

Start Phase 1a (DST), scenario A (spring-forward gap), since it's the highest-blast-
radius unverified item (a mis-resolved DST gap could make an entire window silently
appear/disappear for one day a year, degrading trust in that host's calendar):

1. Open `tests/Unit/SlotGeneratorTest.php` and read the whole file (already done for
   this skill — currently 387 lines) to confirm current line numbers before inserting.
2. Add a new test after the existing timezone-conversion block (`:157-172`), following
   the exact `makeHost` / `addWindow` / `CarbonImmutable::setTestNow` pattern:
   ```php
   it('resolves a window inside a DST spring-forward gap', function () {
       $host = makeHost('America/New_York'); // 2026-03-08: 02:00 skips to 03:00
       $eventType = makeEventType($host, 30);

       CarbonImmutable::setTestNow('2026-03-01 00:00:00'); // a Sunday, ahead of the transition
       addWindow($host, 0, '02:00', '03:00'); // Sunday window that doesn't exist on 2026-03-08

       $date = CarbonImmutable::parse('2026-03-08'); // the transition day (Sunday)
       $slots = (new SlotGenerator)->forDate($eventType, $date, 'UTC');

       // Document whatever Carbon 3.13 actually does here — this assertion is the
       // pinning statement, update it to match observed behavior, then explain
       // in a comment whether that behavior is ACCEPTABLE or a BUG needing a fix.
       expect($slots)->toBeArray();
   })->afterEach(fn () => CarbonImmutable::setTestNow());
   ```
3. Run it standalone first to observe actual behavior:
   `php artisan test --compact --filter=SlotGeneratorTest`
4. Fill in the real assertion based on what you observe, write the classification
   comment (correct vs. bug), and only then decide whether Phase 1a scenario B
   (fall-back) or Phase 1b (date skew) is next — proceed top-to-bottom through this
   plan unless a discovered bug's severity reorders priority (state that reasoning if
   you reorder).
5. After each item is pinned, run the full suite (`php artisan test --compact`) and
   `vendor/bin/pint --dirty` per standard change-control gates before moving to the
   next item.

## Provenance and maintenance

- Backlog source of truth: `bookly-scheduling-domain-reference` SKILL.md section 6,
  as of 2026-07-05/06. That skill owns the raw findings; this skill owns sequencing
  and campaign process. If section 6 changes (items added/removed/reworded), re-read
  it before continuing this campaign — do not trust this document's paraphrases as
  the primary source.
- Carbon version pinned at authoring time: `nesbot/carbon` 3.13.0
  (`composer.lock`, search `"name": "nesbot/carbon"`). Re-verify if `composer.lock`
  changes — DST/fold resolution is a Carbon/tzdata concern, not app code, and can
  shift on a Carbon major-version bump.
- Files read in full to author this campaign: `app/Services/SlotGenerator.php` (121
  lines), `tests/Unit/SlotGeneratorTest.php` (387 lines), plus
  `bookly-scheduling-domain-reference`, `bookly-architecture-contract`,
  `bookly-build-and-env`, and `bookly-change-control` SKILL.md files.
- Could not verify without deeper investigation (left as explicit sub-steps above,
  not assumed): the exact Carbon 3.13 resolution rule for a nonexistent local time
  during a spring-forward gap; whether `PublicBookingController.php`'s in-transaction
  recheck path is actually vulnerable to the host-vs-request date skew (item 2) or
  merely theoretically exposed; whether `BookingController`'s host-initiated cancel
  action bumps `ics_sequence` (item 13, explicitly flagged as unverified upstream).
- When all 13 items are pinned, this skill should be marked complete/retired in favor
  of whatever new campaign or steady-state testing practice follows — update this
  file's frontmatter description or archive it per whatever convention
  `skill-library-builder` establishes for closed campaigns.
