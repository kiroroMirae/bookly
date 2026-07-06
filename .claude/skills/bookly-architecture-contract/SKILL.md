---
name: bookly-architecture-contract
description: Load-bearing design decisions, invariants, and request flows for Bookly. Use when asking "how does booking flow work", "why is X designed this way", reviewing a change that spans controller + model + notification layers, or checking whether a planned feature or change would violate an existing architectural invariant (slugs, signed URLs, locking, policies, status lifecycle).
---

# Bookly Architecture Contract

Verified against the working tree on 2026-07-05. Every claim below has a file:line anchor; if the anchor no longer matches, re-verify before trusting the claim.

Bookly is a Calendly-style scheduling app: hosts (authenticated users) define event types and availability; guests (no accounts) book, cancel, and reschedule via public pages and signed email links. Stack: Laravel 12, Inertia v2, Vue 3, MySQL, Pest (`CLAUDE.md:5-11`).

## When NOT to use this skill

- **Slot math, timezone conversion, buffers/notice/caps internals** → `bookly-scheduling-domain-reference` (owns `SlotGenerator`). This skill only says *where* slot generation is called and *why*.
- **Setup, env vars, PHP version fixes, running the app** → `bookly-build-and-env` / `bookly-run-and-operate`.
- **"Something is broken right now"** → `bookly-debugging-playbook`; past incidents → `bookly-failure-archaeology`.
- **How to make a change safely (tests, pint, migration discipline)** → `bookly-change-control`.

## System shape in one table

| Layer | Location | Notes |
|---|---|---|
| Routes | `routes/web.php` | Single web routes file; catch-all public routes registered LAST (`routes/web.php:57-66`) |
| Controllers (10 non-auth, thin) | `app/Http/Controllers/` | Validation in Form Requests, authorization via `Gate::authorize`. (Auth/ subdirectory has 9 more Breeze-scaffolded controllers, not counted here — see `bookly-validation-and-qa` for the full controller/test map.) |
| Models (5) | `app/Models/` | User, EventType, Booking, AvailabilityWindow, AvailabilityOverride |
| Services (2) | `app/Services/` | `SlotGenerator` (slot math), `IcsGenerator` (RFC 5545 output) |
| Policies (3) | `app/Policies/` | EventTypePolicy, BookingPolicy, AvailabilityOverridePolicy — all pure ownership checks |
| Enum (1) | `app/Enums/BookingStatus.php` | Confirmed / Cancelled / Completed / NoShow |
| Notifications (7) | `app/Notifications/` | All mail; 6 of 7 queued (see weak points) |
| Command (1) | `app/Console/Commands/SendBookingReminders.php` | Scheduled daily in `bootstrap/app.php:25-27` |
| Pages | `resources/js/Pages/` | Auth, Availability, Bookings, EventTypes, Profile, Public, Dashboard.vue |

## Request flows (traced)

### A. Public booking creation — `POST /{username}/{slug}`

Route: `routes/web.php:65` → `PublicBookingController::store` (`app/Http/Controllers/PublicBookingController.php:51`).

1. Event type resolved by username + slug, **must be `is_active`** (`PublicBookingController.php:53-57`). Inactive event types 404 on both show and store.
2. Input validated by `StoreBookingRequest` — `guest_timezone` must pass Laravel's `timezone` rule; `starts_at` must be `after:now` (`app/Http/Requests/StoreBookingRequest.php:11-16`).
3. **Double-booking protection** (`PublicBookingController.php:63-89`): inside `DB::transaction`,
   - `$host->bookings()->lockForUpdate()->get()` (`:66`) takes `SELECT ... FOR UPDATE` on **all of the host's booking rows**, serializing concurrent writers for the same host.
   - Slots are **re-generated inside the lock** via `SlotGenerator::forDate` (`:69`) and the requested `starts_at` must exactly match an open slot (`:71-77`), else `abort(422)`.
   - Only then is the `Booking` created with `status => BookingStatus::Confirmed` (`:79-88`).
   - Design consequence: correctness comes from "re-check availability while holding the lock", not from a DB unique constraint. There is **no unique index on (host_user_id, starts_at)** (`database/migrations/2026_06_29_063548_create_bookings_table.php` — only a non-unique `index(['host_user_id', 'starts_at'])`).
4. After commit: guest gets `GuestBookingConfirmed` via on-demand mail route (guests have no User row — `Notification::route('mail', ...)`, `:93-94`); host gets `HostNewBooking` (`:96`). Both attach an ICS invite.
5. Redirect to confirmation page (`:98-102`), which re-asserts the booking belongs to that username+slug (`:105-111`).

Same lock-then-recheck pattern is copied in both reschedule paths: `GuestBookingController.php:90-112` and `BookingController.php:89-111`.

### B. Guest self-service (cancel / reschedule) — signed URLs

Guests have no accounts (decision, see below); the **signed URL is the credential**.

- Routes wrapped in `signed:relative` middleware (`routes/web.php:58-62`): `booking.manage` (GET), `booking.guest-cancel`, `booking.reschedule` (PATCH).
- Signature validation excludes the `date` query param (`bootstrap/app.php:23` — `validateSignatures(except: ['date'])`) so guests can browse other dates on the manage page without breaking the signature.
- URLs are generated with `URL::signedRoute(..., absolute: false)` — relative signing — in `GuestBookingController::signedActionUrl` (`GuestBookingController.php:142-149`) and in the confirmation email (`app/Notifications/GuestBookingConfirmed.php:29-33`). **No expiry**: these are `signedRoute`, not `temporarySignedRoute`, so links work forever (guard is state-based instead — see next point).
- Every guest action runs two guards: `assertBookingMatchesUrl` (booking must belong to that username+slug, else 404 — `GuestBookingController.php:122-129`) and `canModify` (status is Confirmed **and** `starts_at` is in the future — `:136-140`). Cancelled/past bookings render the manage page read-only with empty slots (`:35-39`).
- Guest cancel: status → Cancelled, notifies host (`HostBookingCancelledByGuest`) and guest (`GuestBookingCancelled`) (`:61-79`). Guest reschedule: lock + re-check, resets `reminder_sent_at` to null and increments `ics_sequence` (`:106-111`) so calendar clients treat the ICS as an update.

### C. Host booking management — `/bookings` (auth)

Routes `routes/web.php:37-40` → `BookingController`.

- `index`: all host bookings eager-loaded with `eventType`, split upcoming/past in PHP (`BookingController.php:24-38`). **Unpaginated** — see weak points.
- `cancel` (`:41-56`): `Gate::authorize('cancel', ...)` → status Cancelled → notifies **guest only** (host initiated it).
- `update` (`:58-73`): status transitions to Completed/NoShow are allowed **only** when the booking is Confirmed and `ends_at` is past (`:64-67`); allowed values whitelisted in `UpdateBookingRequest` (`status in ['completed','no_show']`). Also carries `host_notes`.
- `reschedule` (`:75-117`): same lock-then-recheck as flow A, excludes own booking id from slot collision (`:94`), notifies guest via `GuestBookingRescheduled`.

Notification fan-out matrix (who gets told what):

| Event | Guest notification | Host notification |
|---|---|---|
| New booking | GuestBookingConfirmed | HostNewBooking |
| Guest cancels | GuestBookingCancelled | HostBookingCancelledByGuest |
| Guest reschedules | GuestBookingRescheduled | HostBookingRescheduled |
| Host cancels | GuestBookingCancelled | — |
| Host reschedules | GuestBookingRescheduled | — (no HostBookingRescheduled: `BookingController.php:113-114`) |
| 24h reminder | GuestBookingReminder | — |

### D. ICS calendar feed — `GET /calendar/{token}.ics`

`routes/web.php:50-53` → `CalendarFeedController::show` (`app/Http/Controllers/CalendarFeedController.php:25-42`).

- **Deliberately unauthenticated**: calendar clients can't log in, so the 64-char random token *is* the secret (comment at `CalendarFeedController.php:21-24`; route comment `routes/web.php:49`). Throttled `30,1` (`routes/web.php:52`).
- Token storage: `users.calendar_feed_token`, nullable + unique, 64 chars (`database/migrations/2026_07_03_084609_add_calendar_feed_token_to_users_table.php`), hidden from serialization (`app/Models/User.php:36-40`), lazily created on first use by `User::getOrCreateCalendarFeedToken` (`User.php:82-89`).
- Rotation: `POST /calendar-feed/regenerate` (auth) overwrites with a fresh `Str::random(64)`, instantly invalidating the old URL (`CalendarFeedController.php:47-52`). Profile page surfaces the URL (`app/Http/Controllers/ProfileController.php:24`).
- Feed contents: Confirmed + Completed + NoShow (never Cancelled), last 90 days onward, rendered by `IcsGenerator::forHostFeed` as METHOD:PUBLISH (`CalendarFeedController.php:29-41`, `app/Services/IcsGenerator.php:67-80`).

## Load-bearing decisions (decision → why → invariant → what breaks)

1. **Per-user slugs, immutable after creation** (`CLAUDE.md:21-24`).
   *Why*: booking URLs are `/{username}/{slug}`; uniqueness only needs to hold within a user, and URLs live in guests' emails/bookmarks — regenerating a slug breaks every link ever sent.
   *Invariant*: `UpdateEventTypeRequest` has **no slug field** (`app/Http/Requests/UpdateEventTypeRequest.php:16-29`); slug is set once in `EventTypeController::store` via `uniqueSlug()` scoped to `user_id` (`app/Http/Controllers/EventTypeController.php:35-39, 71-88`).
   *What breaks if violated*: dead links in sent confirmation emails and dashboard share links (`DashboardController.php:49-52`); signed manage URLs 404 via `assertBookingMatchesUrl`.

2. **Guests have no accounts; signed links are the auth** (`README.md:10`, flow B above).
   *Why*: zero-friction booking is the product; an account wall kills conversion.
   *Invariant*: guest identity lives on the booking row (`guest_name`, `guest_email`, `guest_timezone` — `app/Models/Booking.php:16-29`); guest email goes through `Notification::route('mail', ...)`, never a User.
   *What breaks*: adding any guest feature that assumes a user id; forgetting the `signed:relative` middleware on a new guest route silently removes all auth.

3. **Policies for everything, Form Requests for everything** (`CLAUDE.md:23-24, 29-30`).
   *Invariant*: every host mutation calls `Gate::authorize` (e.g. `BookingController.php:43,60,77`; `EventTypeController.php:46,55,64`); all three policies are strict `user->id === owner_id` checks (`app/Policies/BookingPolicy.php:10-23`). No inline `$request->validate()` anywhere in controllers.
   *What breaks*: an unauthorized route lets host A manage host B's bookings — the policies are the *only* ownership barrier (route model binding is unscoped).

4. **All times stored UTC; timezones applied at the edges.**
   *Invariant*: `bookings.starts_at`/`ends_at` are UTC by comment and convention (`create_bookings_table.php` — `// UTC`); controllers parse incoming `starts_at` explicitly as UTC (`PublicBookingController.php:60`). Host timezone lives on `users.timezone`; **guest timezone is captured at booking time** from the validated `guest_timezone` field (`StoreBookingRequest`) — the public page defaults it from `?tz=` query param, whitelist-validated against `timezone_identifiers_list()` (`PublicBookingController.php:30-33`). Guest-facing output converts using the stored value (e.g. `GuestBookingConfirmed.php:27`). Slot math details → `bookly-scheduling-domain-reference`.
   *What breaks*: parsing `starts_at` without the `'UTC'` argument, or formatting guest emails in host timezone — silent one-off meeting-time bugs.

5. **Status lifecycle is enum + guarded transitions, never free-form** (`app/Enums/BookingStatus.php`).
   Transitions actually possible in code:
   - Confirmed → Cancelled: guest (`GuestBookingController::cancel`) or host (`BookingController::cancel`); both may attach `cancellation_reason`.
   - Confirmed → Completed | NoShow: **host only, only after `ends_at` is past** (`BookingController.php:64-67`).
   - Reschedule does NOT change status — it stays Confirmed, only times move.
   - Nothing ever transitions *out of* Cancelled/Completed/NoShow (`canModify` requires Confirmed).
   *What breaks*: adding a transition without updating `canModify`, the feed's status whitelist (`CalendarFeedController.php:31`), and the reminder query (`SendBookingReminders.php:23`) — these three each hard-code status assumptions.

6. **Lock-then-recheck instead of unique constraint for double-booking** (flow A step 3).
   *Why*: "is this slot free" depends on buffers, overrides, caps — not expressible as a DB constraint; so the check is application-level slot regeneration under `FOR UPDATE`.
   *Invariant*: any new write path that creates or moves a booking MUST copy the transaction + `lockForUpdate` + in-lock `SlotGenerator` recheck pattern (three existing copies listed in flow A).
   *What breaks*: a write path that skips the lock reintroduces the race the README advertises as solved.

7. **Notifications are Notification classes, not inline Mailables** (`app/Notifications/`, 7 classes).
   *Why*: one class per business event gives the fan-out matrix above, ICS attachment logic in one place per event, and queueability.
   *Invariant*: 6 of 7 implement `ShouldQueue`; all attach ICS through `IcsGenerator` (`GuestBookingConfirmed.php:44-46`), and reschedules bump `ics_sequence` so calendar clients replace rather than duplicate the event (RFC 5545 SEQUENCE — `IcsGenerator.php:26,36`; bumped at `GuestBookingController.php:110`, `BookingController.php:109`).

8. **Reminders are a scheduled command with a per-row lock** (`app/Console/Commands/SendBookingReminders.php`).
   Window is 23h–25h ahead, Confirmed only, `reminder_sent_at IS NULL` (`:20-27`); each send re-fetches the row under `lockForUpdate` and re-checks `reminder_sent_at` before sending (`:30-41`) so concurrent runs can't double-send. Reschedules null out `reminder_sent_at` so the moved booking gets a fresh reminder. Scheduled `->daily()` in `bootstrap/app.php:25-27` — note it lives in `withSchedule()`, **not** `routes/console.php` (which contains only the `inspire` stub).

## Honest weak points

Each labeled **accepted-for-v1** or **open risk**. Verified in code on 2026-07-05.

| # | Weak point | Evidence | Label |
|---|---|---|---|
| 1 | Daily reminder schedule needs a running scheduler; on Windows/XAMPP dev there is no cron, and `->daily()` at midnight + a 23–25h window means a booking made <23h ahead never gets a reminder | `bootstrap/app.php:26`, `SendBookingReminders.php:20-21` | open risk (silent gap) |
| 2 | All 6 queued notifications go to the `database` queue — **if `php artisan queue:work` isn't running, no email is ever sent**, with no error | `.env:38 QUEUE_CONNECTION=database`; `ShouldQueue` on 6 classes | open risk (ops; see `bookly-run-and-operate`) |
| 3 | `GuestBookingReminder` is the only notification NOT `ShouldQueue` — it sends synchronously inside the command's DB transaction (slow SMTP holds the row lock; a send failure rolls back `reminder_sent_at`, which at least prevents lost reminders) | `app/Notifications/GuestBookingReminder.php` (no interface); `SendBookingReminders.php:30-41` | accepted-for-v1, inconsistent |
| 4 | Locking strategy takes `FOR UPDATE` on **every** booking row of the host and loads them all into memory just to lock — O(total bookings) per booking attempt, and for a host with zero rows protection rests on InnoDB gap-locking behavior | `PublicBookingController.php:66` | accepted-for-v1 (fine at v1 scale) |
| 5 | No CI: `.github/` does not exist; tests/pint run only by convention | verified `ls .github` → absent | open risk |
| 6 | Local PHP is 8.2.12 but CLAUDE.md/README claim PHP 8.3 | `php -v` on this machine; `CLAUDE.md:7`, `README.md:33` | open risk (env drift; see `bookly-build-and-env`) |
| 7 | CLAUDE.md is stale: line 48 still forbids "guest-booking or availability logic until Phase 3 is scoped" while phases 3–9 are shipped (`README.md:19-29`). Its "Key domain concepts" also lag the real schema (no overrides, policies fields) | `CLAUDE.md:44-49` vs codebase | open risk (misleads agents/new devs) |
| 8 | No soft deletes on bookings, and `event_type_id`/`host_user_id` are `cascadeOnDelete` — deleting an event type or user **hard-deletes all its booking history** with no archive | `create_bookings_table.php` (cascades, no `softDeletes()`); `EventTypeController::destroy:62-69` deletes with no booking check | open risk (data loss on event-type delete) |
| 9 | Guest signed URLs never expire (`signedRoute`, not `temporarySignedRoute`) — anyone who obtains an old email can view booking details forever (modification is still blocked by `canModify`) | `GuestBookingController.php:142-149` | accepted-for-v1 |
| 10 | Host bookings index loads ALL bookings unpaginated and splits in PHP; grows unbounded | `BookingController.php:28-33` | accepted-for-v1, revisit |
| 11 | Feed token is stored plaintext in `users` (necessary for lookup, mitigated by 64-char randomness + unique index + throttle + rotation) — a DB leak exposes every host's calendar | `CalendarFeedController.php:27`, migration `2026_07_03_084609` | accepted-for-v1 |
| 12 | `bookings.status` is a plain string column with default `'confirmed'`, not a DB enum/check — the enum cast is the only guard | `create_bookings_table.php` | accepted-for-v1 |

## Provenance and maintenance

- Authored 2026-07-05 by direct inspection of `C:\xampp\htdocs\bookly` (routes, all 10 controllers, 5 models, 3 policies, enum, migrations, notifications, `bootstrap/app.php`, README, CLAUDE.md). No claims taken from README/CLAUDE.md without code verification — two README/CLAUDE.md claims were found stale (weak points 6, 7); the README's "scheduled daily" claim for reminders was **confirmed true** via `bootstrap/app.php:25-27` (it is not in `routes/console.php`, so don't be misled by that file).
- Re-verify anchors after any change to booking flows, routes, or `bootstrap/app.php`. If you add a status transition, a booking write path, or a guest route, update sections "Load-bearing decisions" 5/6 and flow B respectively — or the skill becomes the stale doc it warns about.
- Sibling skills own: slot/timezone math (`bookly-scheduling-domain-reference`), change process (`bookly-change-control`), ops/queue/scheduler runbook (`bookly-run-and-operate`), env drift fixes (`bookly-build-and-env`), incidents (`bookly-failure-archaeology`).
