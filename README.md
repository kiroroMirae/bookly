# Bookly

Calendly-style appointment scheduling built with Laravel 12, Inertia.js v2, and Vue 3. Hosts define event types and weekly availability; guests book, cancel, and reschedule through public pages without an account.

## Features

- **Event types** — name, duration, color, description, per-user booking slug (`/{username}/{slug}`)
- **Weekly availability** — per-day time windows in the host's timezone
- **Public booking** — timezone-aware slot picker with auto-detection, double-booking protection via locking transactions
- **Guest self-service** — signed email links let guests cancel or reschedule without logging in
- **Host tools** — dashboard with upcoming bookings and shareable links, booking list with cancel, email notifications for every booking event, daily reminder command

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
