---
name: bookly-debugging-playbook
description: >
  Triage a live/reported Bookly bug fast — symptom-to-cause lookup, not
  mechanics explanation. Use when: "booking email never arrived", "guest
  didn't get confirmation", "double booking happened", "slot shows available
  but booking fails with 422 / That time slot is no longer available",
  "reminder never sent", "ICS attachment missing or wrong in calendar app",
  "guest manage link 404s" / "cancel link expired" / "reschedule link
  broken", "calendar feed 404" / "subscribe URL doesn't work", "Vite manifest
  error" / "Unable to locate file in Vite manifest", "migrate fails" /
  SQLSTATE connection errors, "wrong timezone shown to guest", "queue not
  processing" / jobs stuck in jobs table, "mail not sending" in dev, "test
  failing after I changed X", 500 error, blank page after deploy, or any
  "why did X happen" / "how do I find out why" question about Bookly runtime
  behavior. This is the FIRST skill to load for any reported bug — it routes
  you to the sibling skill that owns the deep mechanic once localized.
---

# Bookly Debugging Playbook

Verified against the working tree of `C:\xampp\htdocs\bookly` on **2026-07-06**.
192 tests passing (787 assertions) at that commit. This skill is a fast
symptom-to-cause router — it deliberately does not re-explain scheduling
mechanics, ICS internals, or env setup. Follow the "owning skill" link once
you've localized the cause.

## When NOT to use this skill

- You already know the cause and need the mechanic explained in depth
  (slot generation math, override precedence, DST) → `bookly-scheduling-domain-reference`.
- You need to change code and want to know what's safe to touch →
  `bookly-change-control`.
- You need architecture-level "why is it built this way" context →
  `bookly-architecture-contract`.
- You need to start/stop processes, find URLs, or read the process map →
  `bookly-run-and-operate`.
- You need from-scratch setup or a `.env` key reference → `bookly-build-and-env`.
- You want the history of past incidents, prior root causes, or "is this
  actually a bug or a settled decision" → `bookly-failure-archaeology`.
- You need to know what test to write for a fix, or what "done" means for a
  change → `bookly-validation-and-qa`.
- You're planning a deliberate timezone-hardening effort, not triaging a live
  bug → `bookly-timezone-correctness-campaign` (being authored separately;
  check if it exists yet under `.claude/skills/`).

## How to use this table

Find your symptom, run the ONE discriminating check listed, then follow the
"owning skill" link for the deep dive. Do not skip to the deep dive first —
the check usually tells you which of 2-4 candidate causes you actually have
before you go read 300 lines of domain reference.

## 1. Email / notification symptoms

| Symptom | First check | What it tells you | Owning skill for the deep mechanic |
|---|---|---|---|
| "Guest never got confirmation/cancellation/reschedule email" | `MAIL_MAILER` in `.env` — if `log`, open `storage\logs\laravel.log` and search from the bottom for `Subject:`. Found it? Email was "sent" (write-to-log is correct-as-designed in dev). Not found? Check the `jobs` table next. | `MAIL_MAILER=log` means nothing is actually delivered anywhere — this is not a bug, it's the dev mail driver (`config/mail.php`). | `bookly-build-and-env` §2 (mail config), `bookly-run-and-operate` §5 (log format) |
| Email not in log at all, and `jobs` table has rows | `php artisan queue:listen` (or `queue:work`) is not running. `composer run dev` starts it; a manual `php artisan serve` + `npm run dev` combo does not. | 6 of 7 notifications (`GuestBookingConfirmed`, `HostNewBooking`, `GuestBookingCancelled`, `GuestBookingRescheduled`, `HostBookingCancelledByGuest`, `HostBookingRescheduled`) implement `ShouldQueue` and sit in `jobs` (`QUEUE_CONNECTION=database`) until a worker drains them. | `bookly-run-and-operate` §1, §4 |
| Email not in log, `jobs` table empty, `failed_jobs` has rows | `php artisan queue:failed` then `php artisan queue:retry all` (or inspect `failed_jobs.exception` column for the real error — commonly a Mailable render exception, e.g. IcsGenerator throwing on bad data). | The notification threw during send, not before. | Read the stack trace in `failed_jobs.exception`; if it's inside `IcsGenerator`, see `bookly-scheduling-domain-reference` §4 |
| Reminder email never arrived (guest complains 1 day before appt) | Run `php artisan bookings:send-reminders` manually and watch for silent success (no output = normal). Then check `bookings.reminder_sent_at` for that row via tinker. | If `reminder_sent_at` is already set, it WAS sent — check spam/log, not code. If null and the booking's `starts_at` isn't in the now+23h..now+25h window, it simply hasn't hit its window yet — not a bug. If in-window and still null after running the command, something else is wrong (booking status ≠ Confirmed?). | `bookly-run-and-operate` §3 for the scheduling trap (nothing runs `schedule:work` in dev — reminders never fire on their own locally) |
| ICS attachment missing from an email that DID arrive (in the log) | Check which notification it is: `GuestBookingReminder` never attaches ICS by design (`app/Notifications/GuestBookingReminder.php` — verify: `grep -n attachData app/Notifications/GuestBookingReminder.php` returns nothing). All other 6 do. | If it's the reminder notification, "missing ICS" is correct-as-designed, not a bug. | `bookly-run-and-operate` §4 (notification map) |
| ICS attaches but calendar app shows wrong time / duplicate event / can't find the update | Open the raw `.ics` body from `laravel.log` (search for `BEGIN:VCALENDAR`) and check `UID`, `SEQUENCE`, `DTSTART`/`DTEND`. `UID` should be `booking-{id}@bookly` and stable across the confirm→reschedule→cancel lifecycle of one booking; `SEQUENCE` should increment each time. | A client-side dedup problem in the calendar app is usually a UID/SEQUENCE mismatch, not an IcsGenerator bug — verify the raw text before assuming code is wrong. | `bookly-scheduling-domain-reference` §4a (full field table with line anchors) |

## 2. Booking / scheduling symptoms

| Symptom | First check | What it tells you | Owning skill for the deep mechanic |
|---|---|---|---|
| Guest sees a slot as open, but POSTing it returns 422 "That time slot is no longer available." | This is the correct, designed re-check failing — not a crash. Check whether another booking was created for that exact `starts_at` between page-load and submit (race), or whether the requested `starts_at` doesn't exactly match a slot `SlotGenerator` currently emits (stale page, policy changed mid-session, e.g. host edited availability). Query: `Booking::where('host_id', $hostId)->where('status','!=','cancelled')->whereDate('starts_at', $date)->get(['id','starts_at','ends_at','status'])` via tinker. | The lock-and-recheck happens in a DB transaction at `PublicBookingController.php:66,76` (also `GuestBookingController.php:91,103` and `BookingController.php:90,102`) — this is by-design defense against double-booking, not a bug report by itself. Only escalate if the SAME slot ends up double-booked (below). | `bookly-scheduling-domain-reference` §3 |
| Two bookings actually exist overlapping the same host at the same time (real double-booking, not a 422) | Confirm both are `status != cancelled` and actually overlap: `starts_at < other.ends_at AND ends_at > other.starts_at`. If confirmed, this would be a defect in the lock/recheck path — very high severity, since three controllers independently reimplement the same lock pattern. | Before assuming a new bug, check untested edge cases #2 (host-vs-request date skew) and #5 (midnight-crossing bookings) in the scheduling reference — several plausible root causes are already documented as unverified, not fixed. | `bookly-scheduling-domain-reference` §3 and §6 items 2, 5 |
| Slot list is empty when the host swears they have availability that day | Check in this order: (1) any full-day override for that date (`availability_overrides` where date matches, both times null), (2) any `availability_windows` row for that weekday at all, (3) `booking_window_days` on the event type — is the requested date beyond `today + booking_window_days`, (4) `max_bookings_per_day` — has this event type hit its cap for that day. Tinker: `AvailabilityOverride::whereDate('date', $date)->where('user_id', $hostId)->get()` then `AvailabilityWindow::where('user_id', $hostId)->where('day_of_week', $dow)->get()`. | Each of these independently zeroes the slot list; the order above is the actual precedence in `SlotGenerator` (overrides checked before falling back to weekly windows). | `bookly-scheduling-domain-reference` §2 (Stages 1-4) |
| Guest complains the times shown don't match what they expect for their timezone | Confirm what `?tz=` was actually sent (`PublicBookingController::show` validates against `timezone_identifiers_list()` and falls back to `'UTC'` silently on anything invalid — a typo'd IANA id silently becomes UTC, not an error). Also check whether the guest is crossing a date boundary relative to the host (a slot late in the host's day can display as "yesterday" or "tomorrow" for a guest in a very different zone). Likely the known-but-unverified guest-local date rollover behavior (slots render only a time string, not a date) — see `bookly-scheduling-domain-reference` §6 item 3 and `bookly-timezone-correctness-campaign` Phase 3a. Do NOT tell the guest this is "working as intended" — it's an open question, not a settled one. If this recurs, it's a candidate for the timezone campaign. | Silent fallback to UTC on bad `?tz=` is a real, if surprising, behavior — verify the actual query string the guest's browser sent before concluding the slot times are wrong. | `bookly-scheduling-domain-reference` §1, §6 item 3; see also `bookly-timezone-correctness-campaign` Phase 3a for known-unverified DST/skew edges |
| Reschedule doesn't reset the reminder / sends a duplicate reminder | Check `bookings.reminder_sent_at` after the reschedule — both `GuestBookingController::reschedule` (line ~110) and `BookingController::reschedule` (line ~109) reset it to null so a new reminder can fire for the new time. If it's still set post-reschedule, that reschedule path has a defect. | This is a one-line invariant, easy to regress if someone edits one reschedule path and forgets its twin (guest-initiated vs host-initiated are two separate controllers with duplicated logic). | `bookly-scheduling-domain-reference` §3; `bookly-architecture-contract` for the duplication-across-controllers note |

## 3. Link / access symptoms

| Symptom | First check | What it tells you | Owning skill for the deep mechanic |
|---|---|---|---|
| Guest manage/cancel/reschedule link 404s or shows "invalid signature" | The route is `signed:relative` middleware (`routes/web.php:58-61`). A link expires or breaks if: the URL was truncated/modified in transit (check the raw link in `laravel.log`, search for `booking.manage`), or `APP_URL` changed between when the signed link was generated and now (signature is computed over the URL). | You cannot hand-type a working manage URL — it must come from an actual generated signed link. If testing manually, generate one in tinker: `URL::signedRoute('booking.manage', ['username' => ..., 'slug' => ..., 'booking' => $id])`. | `bookly-run-and-operate` §2 (route/signature notes) |
| Host's calendar subscribe feed 404s | `CalendarFeedController::show` does `User::where('calendar_feed_token', $token)->firstOrFail()` (`app/Http/Controllers/CalendarFeedController.php:27`) — a 404 means the token doesn't match ANY user, which happens instantly after the host clicks "regenerate" (`calendar-feed.regenerate` rotates `calendar_feed_token` via `Str::random(64)` and the old URL dies immediately, no grace period). Confirm via tinker: `User::where('calendar_feed_token', $token)->exists()`. | Old bookmarked feed URLs breaking after regeneration is expected behavior, not a bug. | `bookly-scheduling-domain-reference` §4b |
| Calendar feed loads but is missing expected bookings | Feed only includes Confirmed + Completed + NoShow bookings from the last 90 days onward (`HISTORY_DAYS`) — Cancelled bookings are excluded by design. Check the booking's `status` and `starts_at` age. | Not a bug if the booking is Cancelled or older than 90 days back. | `bookly-scheduling-domain-reference` §4b, §6 item 12 (no verified upper bound) |

## 4. Build / test / environment symptoms

| Symptom | First check | What it tells you | Owning skill for the deep mechanic |
|---|---|---|---|
| `Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest: resources/js/Pages/X/Y.vue` | Run `npm run build` and re-run the failing test/page. | A new `.vue` page exists on disk but the manifest (`public/build/manifest.json`) hasn't been regenerated — this happens after every new Inertia page is added and is the single most common "test suddenly fails" cause in this repo. | `bookly-build-and-env` §3 (the npm-run-build-before-tests rule) |
| `SQLSTATE[HY000] [2002] No connection could be made...` on `migrate` or `artisan tinker` DB calls | XAMPP MySQL service isn't running, or wrong port. Open XAMPP control panel and start MySQL; confirm `DB_PORT=3306` in `.env` matches. | Feature/unit tests still pass even with MySQL down — they use in-memory SQLite (`phpunit.xml`). A green test suite does NOT mean MySQL is reachable; check both independently. | `bookly-build-and-env` §1, §3 |
| `SQLSTATE[HY000] [1049] Unknown database 'bookly'` | `C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS bookly"` then re-run `migrate`. | Fresh clone never auto-creates the DB. | `bookly-build-and-env` §1 |
| A test that passed yesterday fails today with no code change in that area | Run `php artisan config:clear` first — a stale config cache after any `.env` edit is a common false-fail. Then re-run `php artisan test --compact --filter=<TestName>` in isolation to rule out test-order pollution. | `composer run test` clears config automatically; a plain `php artisan test` does not. | `bookly-build-and-env` §3, §4 |
| Any 500 error with no other clue | `storage\logs\laravel.log` — single file, tail it, search for the most recent stack trace (timestamps are UTC since `config('app.timezone')` is hardcoded UTC). `APP_DEBUG=true` in dev also renders the trace in-browser. | This is the ONLY log file in this app — there's no per-channel split (`LOG_CHANNEL=stack`, `LOG_STACK=single`). | `bookly-run-and-operate` §5 |
| Local PHP version confusion / a PHP 8.3-only feature fatals | Local XAMPP CLI is PHP 8.2.12 as of 2026-07-05 despite `CLAUDE.md`/`README.md` claiming 8.3; `composer.json` only requires `^8.2`. Run `php -v` to confirm current state before assuming 8.3 features are safe. | Known, documented drift — not a regression. | `bookly-build-and-env` §1 |

## 5. General triage order (when the symptom isn't in the tables above)

1. **Reproduce with the smallest possible input.** Tinker (`php artisan tinker`) to hit the service/model directly (e.g. `SlotGenerator::forDate(...)`) before assuming the bug is in the controller or the UI.
2. **Check `storage\logs\laravel.log` first, always** — it captures app errors AND (in dev) every outgoing email. One file, one search.
3. **Check the `jobs` / `failed_jobs` tables** whenever a queued side-effect (any notification except `GuestBookingReminder`) seems to be missing. Both tables come from the standard Laravel migration `database/migrations/0001_01_01_000002_create_jobs_table.php`.
4. **Run the narrowest test filter that touches the suspect code** (`php artisan test --compact --filter=<Name>`) before writing a new reproduction test from scratch — the 192-test suite already pins most SlotGenerator/IcsGenerator/controller behavior; a red test there is often faster signal than manual reproduction.
5. **Don't fix what's "working as designed."** `MAIL_MAILER=log`, silent `?tz=` fallback to UTC, 422 on stale slots, feed URL death on token rotation, and no-scheduler-in-dev are all intentional or at minimum already-known — check `bookly-run-and-operate` and `bookly-build-and-env` "known traps" sections before treating one of these as a new bug.
6. **If you find a real defect**, do not silently patch it if it touches slug/policy/lock/status invariants — route through `bookly-change-control` first.

## Provenance and maintenance

Verified 2026-07-06 by direct inspection of:
- `app/Http/Controllers/{PublicBookingController,GuestBookingController,BookingController,CalendarFeedController}.php`
  (line numbers for `lockForUpdate`, `abort(422, ...)`, `notify(`/`Notification::route` calls,
  `firstOrFail` on `calendar_feed_token`)
- `app/Console/Commands/SendBookingReminders.php` (read in full — 23h/25h window,
  `DB::transaction` + `lockForUpdate` idempotency guard, silent success)
- `routes/web.php` (signed-route group lines 58-61)
- `database/migrations/0001_01_01_000002_create_jobs_table.php` (confirms `jobs`,
  `job_batches`, `failed_jobs` tables exist from the stock Laravel migration)
- Live run of `php artisan test --compact`: **192 passed, 787 assertions**, ~9s, 2026-07-06.
- `grep -rn "TODO\|FIXME" app resources/js` → zero results, 2026-07-06.
- `git log --oneline` → 10 commits total (phases 1-9) on `main`, 2026-07-06.
- Cross-checked against `bookly-run-and-operate` (URL map, notification map,
  process map) and `bookly-build-and-env` (env keys, known traps) to avoid
  duplicating their content — this skill only adds the symptom→check routing layer.

**Could not verify at authoring time:** whether `bookly-failure-archaeology` and
`bookly-timezone-correctness-campaign` exist on disk yet (referenced as forward
links; a sibling agent was authoring them in parallel). If they don't exist when
you read this, treat those references as aspirational and fall back to
`bookly-scheduling-domain-reference` §6 for the same untested-edge-case content.

**Re-verify when:** any of the three booking controllers change their
lock/recheck logic (line numbers will drift), a new notification class is
added (update §1's table), the mail driver or queue connection changes in
`.env.example`, or the test count moves meaningfully (rerun
`php artisan test --compact` and update the count above).
