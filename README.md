# Bookly

Calendly-style appointment scheduling built with Laravel 12, Inertia.js v2, and Vue 3. Hosts define event types and weekly availability; guests book, cancel, and reschedule through public pages without an account.

## Features

- **Event types** — name, duration, color, description, per-user booking slug (`/{username}/{slug}`)
- **Weekly availability** — per-day time windows in the host's timezone
- **Public booking** — timezone-aware slot picker with auto-detection, double-booking protection via locking transactions
- **Guest self-service** — signed email links let guests cancel or reschedule without logging in
- **Host tools** — dashboard with upcoming bookings and shareable links, booking list with cancel/reschedule and completed/no-show marking, email notifications for every booking event, daily reminder command
- **Booking policies** — per-event-type buffers before/after, minimum notice, rolling booking window, and daily booking caps enforced during slot generation
- **Date overrides** — block specific dates or replace weekly hours for a single day (holidays, one-off schedule changes)
- **Calendar invites** — RFC 5545 `.ics` attachments on confirmation, reschedule, and cancellation emails so bookings land in guests' and hosts' calendars

## Status

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Scaffold, schema, models, enums | Done |
| 2 | Event Types CRUD | Done |
| 3–4 | Availability, public booking, notifications | Done |
| 5 | Guest self-service, dashboard, timezone UX | Done |
| 6 | Booking policies, host booking management | Done |
| 7 | Date-specific availability overrides | Done |
| 8 | ICS calendar invites on booking emails | Done |
| Next | Candidates: ICS subscribe feed for hosts, cancellation reason & audit trail | Planned |

## Stack

- Laravel 12 (PHP 8.3), MySQL
- Inertia.js v2 + Vue 3 (Composition API), Tailwind CSS v3, Vite
- Pest v3 for tests, Pint for formatting

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# configure DB_* in .env, then:
php artisan migrate --seed
npm run build
```

## Development

```bash
php artisan serve   # plus `npm run dev` in a second terminal
# or
composer run dev
```

## Testing

```bash
php artisan test --compact
vendor/bin/pint --dirty
```

Booking reminders are sent by `php artisan bookings:send-reminders`, scheduled daily.
