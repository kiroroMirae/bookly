---
name: bookly-run-and-operate
description: >
  Run and operate the Bookly app day-to-day. Use when asked to "start the app",
  "run bookly", get the "URL for" any page (public booking page, dashboard,
  event types, bookings, availability), "send reminders" / run
  bookings:send-reminders, "where do emails go" / find sent mail, "log in as"
  the demo user, get the "ICS feed URL" / calendar subscribe link, or find
  logs and artifacts.
---

# Bookly: Run and Operate

Verified against the repo at `C:\xampp\htdocs\bookly` on 2026-07-05.
Windows 11 + XAMPP (MySQL), PHP artisan serve + Vite dev server.

## When NOT to use this skill

- Setting up the environment from scratch or env-var questions → `bookly-build-and-env`
- How scheduling/slot logic works → `bookly-scheduling-domain-reference`
- Debugging a broken behavior → `bookly-debugging-playbook`
- Timezone bugs → `bookly-timezone-correctness-campaign`
- Making code changes → `bookly-change-control`

## 1. Process anatomy — what must be running

```powershell
cd C:\xampp\htdocs\bookly
composer run dev
```

`composer run dev` (verified in `composer.json` scripts) runs three processes via
concurrently: **server** (`php artisan serve`, http://127.0.0.1:8000), **queue**
(`php artisan queue:listen --tries=1 --timeout=0`), **vite** (`npm run dev`).
`--kill-others`: if one dies, all die.

| Process | Required? | Why |
|---|---|---|
| `php artisan serve` | Yes | The app |
| `npm run dev` (Vite) | Yes (dev) | Without it: Vite manifest error. Alternative: `npm run build` once |
| `php artisan queue:listen` | **Yes for emails** | `QUEUE_CONNECTION=database` and 6 of 7 notifications implement `ShouldQueue`. No worker = booking/cancel/reschedule emails sit in the `jobs` table forever |
| `php artisan schedule:work` | **Not started by anything** | See section 3 — the reminder schedule never fires in dev without it |

MySQL must be running (XAMPP control panel). Plain manual alternative:
`php artisan serve` + `npm run dev` + `php artisan queue:listen` in three terminals.

## 2. URL map (from `php artisan route:list`, 2026-07-05)

Base: `http://127.0.0.1:8000` (APP_URL in `.env` is `http://localhost` — signed
URLs use `signed:relative` middleware, so host mismatch does not break signatures).

### Public (no auth)

| URL | Route name | Controller |
|---|---|---|
| `/` | — | Welcome page (closure, `routes/web.php:16`) |
| `/{username}/{slug}` GET | `booking.show` | `PublicBookingController@show` — the public booking page |
| `/{username}/{slug}` POST | `booking.store` | `PublicBookingController@store` |
| `/{username}/{slug}/confirmation/{booking}` | `booking.confirmation` | `PublicBookingController@confirmation` |
| `/register`, `/login`, `/forgot-password` | breeze defaults | `routes/auth.php` |

### Token / signature protected (guest, no login)

| URL | Protection | Controller |
|---|---|---|
| `/{username}/{slug}/manage/{booking}` GET | `signed:relative` — link only valid from the signed URL in guest emails | `GuestBookingController@show` |
| `.../manage/{booking}/cancel` PATCH | signed | `GuestBookingController@cancel` |
| `.../manage/{booking}/reschedule` PATCH | signed | `GuestBookingController@reschedule` |
| `/calendar/{token}.ics` GET | token IS the secret (64-char, `users.calendar_feed_token`), `throttle:30,1` | `CalendarFeedController@show` — host's ICS subscribe feed |

You cannot hand-type a guest manage URL; generate one via
`URL::signedRoute('booking.manage', [...])` in tinker or copy it from the email in the log.

### Authenticated host (`auth` middleware; `verified` on dashboard is a no-op — `User` does NOT implement `MustVerifyEmail`)

| URL | Route name | Controller |
|---|---|---|
| `/dashboard` | `dashboard` | `DashboardController@index` |
| `/event-types` (+ full resource CRUD) | `event-types.*` | `EventTypeController` |
| `/availability` GET/PUT | `availability.edit/update` | `AvailabilityController` |
| `/availability/overrides` POST, `/availability/overrides/{override}` DELETE | `availability.overrides.*` | `AvailabilityOverrideController` |
| `/bookings` GET, `/bookings/{booking}` PATCH, `/cancel`, `/reschedule` | `bookings.*` | `BookingController` |
| `/profile` GET/PATCH/DELETE | `profile.*` | `ProfileController` |
| `/calendar-feed/regenerate` POST | `calendar-feed.regenerate` | rotates `calendar_feed_token` (`Str::random(64)`), old feed URL dies instantly |

## 3. Console command: `bookings:send-reminders`

```powershell
cd C:\xampp\htdocs\bookly
php artisan bookings:send-reminders
```

`app/Console/Commands/SendBookingReminders.php`. Selects bookings that are
`status = confirmed`, `starts_at` between now+23h and now+25h, and
`reminder_sent_at IS NULL`. Sends `GuestBookingReminder` to `guest_email`, then
sets `reminder_sent_at`.

- **Idempotent: YES.** Each send happens in a `DB::transaction` with
  `lockForUpdate()` re-check of `reminder_sent_at`. Running it twice sends nothing twice.
- **Output: none** on success (no `$this->info` calls); exit code 0. Silence is normal.
- **Sends synchronously** — `GuestBookingReminder` is the ONE notification that does
  NOT implement `ShouldQueue`, so it does not need the queue worker.

### The scheduling trap (doc/code state, verified 2026-07-05)

The command **IS registered in the scheduler** — in `bootstrap/app.php:25-26`
(`->withSchedule(fn (Schedule $s) => $s->command(SendBookingReminders::class)->daily())`),
NOT in `routes/console.php` (which contains only the default `inspire` command —
don't conclude "not scheduled" from that file). `php artisan schedule:list` confirms:
`0 0 * * *  php artisan bookings:send-reminders`.

**BUT the schedule never fires in dev**: nothing runs the scheduler. `composer run dev`
starts server/queue/vite only — no `schedule:work`, and there is no Windows Task
Scheduler / cron entry. Practical consequences:

- Reminders in dev: run the command manually, or start `php artisan schedule:work`
  as a fourth process.
- `daily()` = midnight **server timezone** (`APP_TIMEZONE` unset → UTC), while the
  23–25h window is a 2h-wide net around the daily tick — a booking created after the
  day's tick for <24h out can miss its reminder. That's domain territory
  (`bookly-scheduling-domain-reference`); don't fix it from here.
- README line 64 says "scheduled daily" — true at the code level, misleading
  operationally. Report divergence via `bookly-change-control`; do not silently fix.

## 4. Notifications map (all in `app/Notifications/`, all mail-channel)

| Event | Notification | Recipient | Queued? | ICS attached? |
|---|---|---|---|---|
| Guest books (`PublicBookingController@store:93-96`) | `GuestBookingConfirmed` | guest email (on-demand route) | Yes | Yes (`invite.ics`, REQUEST) |
| Guest books (same trigger) | `HostNewBooking` | host user | Yes | Yes |
| Host cancels (`BookingController@cancel:52`) | `GuestBookingCancelled` | guest | Yes | Yes (METHOD:CANCEL mime) |
| Host reschedules (`BookingController@reschedule:113`) | `GuestBookingRescheduled` | guest | Yes | Yes |
| Guest cancels (`GuestBookingController@cancel:73-76`) | `HostBookingCancelledByGuest` + `GuestBookingCancelled` | host + guest | Yes | Yes (CANCEL) |
| Guest reschedules (`GuestBookingController@reschedule:114-117`) | `HostBookingRescheduled` + `GuestBookingRescheduled` | host + guest | Yes | Yes |
| Reminder command | `GuestBookingReminder` | guest | **No (sync)** | **No** |

All 7 verified: 6 implement `ShouldQueue`; all except `GuestBookingReminder` attach
an ICS via `App\Services\IcsGenerator` (`attachData(..., 'invite.ics')`).
Bookings carry `ics_sequence` for RFC 5545 update sequencing.

## 5. Where things land

| Thing | Location |
|---|---|
| App log | `C:\xampp\htdocs\bookly\storage\logs\laravel.log` (single file) |
| **Emails** | `MAIL_MAILER=log` in `.env` → full rendered emails (incl. signed manage URLs and base64 ICS attachments) are written into `laravel.log`. Nothing is actually sent. Search for `Subject:` |
| Queued jobs | `jobs` table (database queue). Failed → `failed_jobs`. Retry: `php artisan queue:retry all` |
| ICS files | Never written to disk — generated in-memory (email attachments + streamed feed response) |
| Vite build output | `public/build/` after `npm run build` |

To grab the latest emailed link: open `laravel.log`, search from the bottom for
`booking.manage`-style URLs or `Subject: `.

## 6. Seeded demo state

`database/seeders/DatabaseSeeder.php` creates exactly **one user** via factory:

- Email: `test@example.com` — Password: `password` (factory default, `Hash::make('password')`)
- Username: **random** (`fake()->unique()->userName()`) — check with
  `php artisan tinker --execute 'echo App\Models\User::first()->username;'`
- Email pre-verified. **No event types, no availability windows are seeded.**

So there is NO working public booking page out of the box. Fastest path to one:

1. `php artisan migrate:fresh --seed` (destructive) or just `php artisan db:seed`
2. Log in at `/login` as `test@example.com` / `password`
3. `/availability` — set weekly hours
4. `/event-types/create` — create an event type; note its slug
5. Visit `/{username}/{slug}` in a private window — that's the guest view

## Provenance and maintenance

- Written 2026-07-05 by inspecting `C:\xampp\htdocs\bookly` directly: `composer.json`,
  `routes/web.php`, `routes/console.php`, `bootstrap/app.php`,
  `app/Console/Commands/SendBookingReminders.php`, all 7 files in `app/Notifications/`,
  controllers (grep for `notify(`), `database/seeders/DatabaseSeeder.php`,
  `database/factories/UserFactory.php`, `.env` / `.env.example`, `README.md`, and live
  output of `php artisan route:list` and `php artisan schedule:list`.
- Note: an earlier belief held that the reminder command was unscheduled because
  `routes/console.php` is empty. Re-verified 2026-07-05: it IS scheduled in
  `bootstrap/app.php` `withSchedule`. The operational gap (no scheduler process runs
  in dev) remains real.
- Re-verify when touched: route map ↔ `routes/web.php`; process list ↔ `composer.json`
  `dev` script; notification table ↔ `app/Notifications/` + controller triggers;
  schedule ↔ `bootstrap/app.php`; mail driver ↔ `.env`.
- Sibling skills: bookly-build-and-env (setup/env), bookly-scheduling-domain-reference
  (slot/window logic), bookly-debugging-playbook, bookly-change-control,
  bookly-validation-and-qa, bookly-timezone-correctness-campaign,
  bookly-architecture-contract, bookly-failure-archaeology, bookly-research-frontier.
