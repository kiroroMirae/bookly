---
name: bookly-build-and-env
description: >-
  Recreate the Bookly dev environment from scratch and look up any
  configuration value. Use when: setting up the project on a new machine,
  "composer install fails", "npm install / npm run build fails",
  "what does this .env key do", "where is MAIL_MAILER consumed",
  "Vite manifest" errors (Unable to locate file in Vite manifest),
  "migrate fails" / SQLSTATE connection errors, "mail not sending",
  enabling PHP extensions in XAMPP php.ini, phpunit/Pest test-DB questions,
  or comparing `composer run setup` vs the README setup steps.
---

# Bookly — Build & Environment

Laravel 12 + Inertia v2 + Vue 3 (Breeze auth) appointment-scheduling app.
Dev machine: Windows 11 + XAMPP (MySQL on 3306). Repo: `C:\xampp\htdocs\bookly`.

## When NOT to use this skill

- Writing feature code / slot logic → `bookly-scheduling-domain-reference`
- Debugging runtime bugs (not env/build) → `bookly-debugging-playbook`
- Day-to-day serve/queue/scheduler operation → `bookly-run-and-operate`
- Test-writing patterns → `bookly-validation-and-qa` / `pest-testing`
- Changing contracts, migrations policy → `bookly-change-control`

All volatile facts below verified against the repo and local toolchain on
**2026-07-05** unless stamped otherwise.

## 1. From-scratch setup runbook (Windows / XAMPP)

Prerequisites (verified 2026-07-05): PHP 8.2.12 (XAMPP CLI), Composer 2.8.3,
Node v22.21.1, npm 10.9.4, XAMPP MySQL running on 3306.

> **PHP version drift (known, 2026-07-05):** `CLAUDE.md` and `README.md` both
> claim "PHP 8.3", but the local XAMPP PHP is **8.2.12**. `composer.json`
> requires only `"php": "^8.2"`, so 8.2.12 works. Treat the docs' "8.3" as
> aspirational, not a hard requirement.

Ordered steps (this is the README flow, which is the correct one for MySQL dev):

```powershell
cd C:\xampp\htdocs\bookly
composer install
npm install
copy .env.example .env
php artisan key:generate

# .env.example ships DB_CONNECTION=sqlite. For the standard XAMPP setup,
# edit .env to:
#   DB_CONNECTION=mysql
#   DB_HOST=127.0.0.1
#   DB_PORT=3306
#   DB_DATABASE=bookly        <- create it first (next line)
#   DB_USERNAME=root
#   DB_PASSWORD=

# Create the database (XAMPP root has no password by default):
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS bookly"

php artisan migrate --seed
npm run build
```

Run it: `composer run dev` (concurrently: `php artisan serve` +
`php artisan queue:listen --tries=1 --timeout=0` + `npm run dev`), or
`php artisan serve` + `npm run dev` in two terminals.

**You know it worked when:**
1. `php artisan test --compact` is fully green (tests use in-memory SQLite,
   so they pass even if MySQL is misconfigured — do both checks).
2. `http://localhost:8000/login` accepts the seeded user:
   **test@example.com / password** (`DatabaseSeeder` creates one
   `Test User` via factory; factory default password is `password`).
3. `php artisan about` shows `Environment: local`, `Database: mysql`.

### `composer run setup` vs README steps — they diverge

`composer.json` defines a `setup` script:
`composer install` → copy `.env.example`→`.env` if missing →
`key:generate` → **`migrate --force`** → `npm install` → `npm run build`.

Divergences from the README flow (verified 2026-07-05):
- **`setup` never seeds.** README uses `migrate --seed`; `setup` uses
  `migrate --force`. After `composer run setup` there is **no user to log in
  with** — run `php artisan db:seed` yourself.
- **`setup` migrates against whatever `.env` says at that moment.** On a
  fresh clone that's the `.env.example` default `DB_CONNECTION=sqlite`
  (file `database/database.sqlite`), *not* MySQL. If you want MySQL you must
  edit `.env` before the migrate step — which `setup` gives you no window
  for. Practical rule: **use the README steps on a new machine; use
  `composer run setup` only when you're happy with sqlite or `.env` already
  exists and is correct.**
- `--force` suppresses the production confirmation prompt; it does not make
  migrations safer. Harmless locally, but don't cargo-cult it.

## 2. Config catalog (every meaningful .env key)

The live `.env` has exactly the same keyset as `.env.example` — no extra
app-specific keys exist (verified 2026-07-05). Local dev values that differ
from `.env.example`: `DB_CONNECTION=mysql` (example ships `sqlite`).

Check any value with: `php artisan config:show <file>.<key>`
(e.g. `php artisan config:show database.default`).

| Key | .env.example default | Consumed in | Dev / prod notes |
|---|---|---|---|
| APP_NAME | Laravel | config/app.php | Also feeds `MAIL_FROM_NAME` and `VITE_APP_NAME`. |
| APP_ENV | local | config/app.php | `testing` forced by phpunit.xml. |
| APP_KEY | (empty) | config/app.php | Set by `key:generate`. Missing key → "No application encryption key" 500. |
| APP_DEBUG | true | config/app.php | Must be `false` in prod. |
| APP_URL | http://localhost | config/app.php | Signed guest cancel/reschedule links and ICS links are built from this — wrong APP_URL = broken email links. |
| APP_LOCALE / APP_FALLBACK_LOCALE / APP_FAKER_LOCALE | en / en / en_US | config/app.php | Rarely touched. |
| APP_MAINTENANCE_DRIVER | file | config/app.php | phpunit.xml pins `file`. |
| BCRYPT_ROUNDS | 12 | config/hashing.php | phpunit.xml drops to 4 for speed. |
| LOG_CHANNEL / LOG_STACK / LOG_LEVEL / LOG_DEPRECATIONS_CHANNEL | stack / single / debug / null | config/logging.php | Logs land in `storage/logs/laravel.log`. |
| DB_CONNECTION | sqlite (example) / **mysql (local dev)** | config/database.php | Tests override to sqlite `:memory:`. |
| DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD | commented out in example | config/database.php | XAMPP: 127.0.0.1 / 3306 / bookly / root / (empty). |
| SESSION_DRIVER | database | config/session.php | Needs the `sessions` table (created in the `0001_..._create_users_table.php` batch) — login breaks if migrations haven't run. Tests use `array`. |
| SESSION_LIFETIME / ENCRYPT / PATH / DOMAIN | 120 / false / / / null | config/session.php | Defaults fine for dev. |
| BROADCAST_CONNECTION | log | config/broadcasting.php | No realtime features; tests use `null`. |
| FILESYSTEM_DISK | local | config/filesystems.php | No user uploads in Bookly v1. |
| QUEUE_CONNECTION | database | config/queue.php | Notifications queue here — **without a queue worker, no emails go out**. `composer run dev` includes `queue:listen`. Tests use `sync`. |
| CACHE_STORE | database | config/cache.php | Uses the `cache` table. Tests use `array`. |
| MEMCACHED_HOST / REDIS_* | 127.0.0.1 / defaults | config/database.php, config/cache.php | Unused locally (redis driver not selected; phpredis ext not needed). |
| MAIL_MAILER | log | config/mail.php | **Dev default: emails are written to `storage/logs/laravel.log`, not sent.** "Mail not sending" in dev is usually this, working as designed. For real SMTP set MAIL_MAILER=smtp + HOST/PORT/USERNAME/PASSWORD/SCHEME. Tests use `array`. |
| MAIL_SCHEME / HOST / PORT / USERNAME / PASSWORD | null / 127.0.0.1 / 2525 / null / null | config/mail.php | Only relevant when MAILER=smtp. |
| MAIL_FROM_ADDRESS / MAIL_FROM_NAME | hello@example.com / ${APP_NAME} | config/mail.php | From-header on all booking notifications. |
| AWS_* | empty | config/filesystems.php, config/queue.php (sqs) | Unused; ignore. |
| VITE_APP_NAME | ${APP_NAME} | resources/js (import.meta.env) | Frontend page titles. |

### Timezone configuration (critical in this domain)

- `config('app.timezone')` is **hardcoded to `'UTC'`** in `config/app.php`
  (line ~68). There is **no `APP_TIMEZONE` env key** — a grep of `env(` in
  `config/app.php` confirms the timezone is not env-driven. Do not add one
  casually; the domain code assumes UTC storage.
- Bookings are stored in UTC (`starts_at`/`ends_at`); display and slot
  generation use the **per-user `users.timezone` column**:
  `SlotGenerator` uses `$host->timezone`, notifications use
  `$notifiable->timezone ?? 'UTC'`, `PublicBookingController` uses
  `CarbonImmutable::today($eventType->user->timezone)`.
- Deep dive on correctness rules → `bookly-timezone-correctness-campaign`.

### Scheduler

`bootstrap/app.php` → `->withSchedule(...)` runs
`App\Console\Commands\SendBookingReminders` **daily**. Locally nothing
triggers it unless you run `php artisan schedule:work` (or invoke the
command manually). `routes/console.php` contains only the stock `inspire`
command.

## 3. Test environment (phpunit.xml — exact overrides)

`phpunit.xml` `<php>` block (verified 2026-07-05):

| Var | Test value | Effect |
|---|---|---|
| APP_ENV | testing | |
| DB_CONNECTION / DB_DATABASE | sqlite / `:memory:` | **Tests never touch your MySQL data.** Requires `pdo_sqlite` (loaded). Also means tests can pass while dev MySQL config is broken — and SQLite/MySQL behavior can diverge (strictness, collations). |
| DB_URL | (empty) | Prevents an inherited DB_URL overriding the sqlite settings. |
| MAIL_MAILER | array | Mail captured in memory; assert with fakes. |
| QUEUE_CONNECTION | sync | Queued notifications execute inline in tests. |
| SESSION_DRIVER / CACHE_STORE | array / array | No DB tables needed. |
| BCRYPT_ROUNDS | 4 | Faster hashing. |
| BROADCAST_CONNECTION=null; PULSE/TELESCOPE/NIGHTWATCH_ENABLED=false | — | Defensive; none of those packages are installed. |

Run: `php artisan test --compact`. The composer alias `composer run test`
also does `config:clear` first — use it after fiddling with cached config.

### The npm-run-build-before-tests rule

CLAUDE.md: *"After adding new Vue pages: `npm run build` (Vite manifest must
include new files for tests to pass)."* Feature tests render Inertia pages;
Laravel's Vite helper resolves each page file through
`public/build/manifest.json`. A new `resources/js/Pages/**.vue` that exists
on disk but not in the manifest fails every test hitting that route with:

```
Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest:
resources/js/Pages/<Your>/<Page>.vue
```

Fix: `npm run build`. The same exception in the browser means neither
`npm run dev` is running nor a build exists. Vite entrypoint is
`resources/js/app.js` (`vite.config.js`).

## 4. Known traps

- **No CI exists.** There is no `.github/` directory and no workflows
  (verified 2026-07-05). Tests run **locally only** — nothing will catch a
  red suite you didn't run. Always run `php artisan test --compact` and
  `vendor/bin/pint --dirty` yourself before committing.
- **PHP drift:** local CLI is 8.2.12; docs say 8.3 (see §1). PHP-8.3-only
  syntax (e.g. typed class constants) will fatal locally.
- **XAMPP php.ini extensions:** Bookly needs `pdo_mysql`, `pdo_sqlite`
  (tests), `openssl`, `mbstring`, `curl`, `fileinfo`, `zip` — all confirmed
  loaded on 2026-07-05. **`intl` is NOT loaded and NOT required**: a grep of
  `app/` finds no intl usage; ICS generation is hand-built RFC 5545 and
  timezone math uses Carbon/`DateTimeZone` (core PHP). `gd` is loaded but
  unused. If an extension is missing, enable it in `C:\xampp\php\php.ini`
  (uncomment `;extension=<name>`); the CLI reads the same php.ini, no Apache
  restart needed for artisan commands — just reopen the terminal.
- **`composer run setup` on a fresh clone migrates into SQLite and skips
  seeding** (§1). Symptom: app boots, login fails, `database/database.sqlite`
  silently appeared. Fix `.env` to mysql, then `php artisan migrate --seed`.
- **`migrate` fails with `SQLSTATE[HY000] [2002]`** → XAMPP MySQL not
  started or wrong port. `SQLSTATE[HY000] [1049] Unknown database 'bookly'`
  → create the DB first (§1).
- **Mail "not sending" in dev** is usually correct behavior twice over:
  `MAIL_MAILER=log` writes mail to `storage/logs/laravel.log`, AND queued
  notifications need a worker (`QUEUE_CONNECTION=database`) — without
  `queue:listen`/`queue:work`, mail jobs sit unsent in the `jobs` table.
- **Stale config cache:** if a `.env` edit "does nothing", run
  `php artisan config:clear`. `composer run test` does this automatically;
  plain `php artisan test` does not.
- **Windows quirks:** use `copy` (cmd/PowerShell), path
  `C:\xampp\htdocs\bookly` (Git Bash: `/c/xampp/htdocs/bookly`);
  `concurrently` (used by `composer run dev`) is an npm devDependency, so
  `npm install` must precede `composer run dev`; if port 8000 is taken use
  `php artisan serve --port=8001` and keep APP_URL consistent for links.

## Provenance and maintenance

- Ground truth files: `composer.json` (scripts), `package.json`,
  `.env.example`, `phpunit.xml`, `config/app.php` and other `config/*.php`,
  `README.md` (Setup), `CLAUDE.md`, `database/seeders/DatabaseSeeder.php`,
  `bootstrap/app.php` (schedule), `vite.config.js`.
- Toolchain versions and loaded extensions verified on the dev machine
  **2026-07-05** (`php -v`, `php -m`, `node -v`, `composer --version`).
  Re-verify after any XAMPP/Node upgrade and update the date stamps.
- Update this skill when: a new `.env` key is introduced (add it to the
  catalog with its config consumer), the `setup` composer script changes,
  phpunit.xml overrides change, CI is added (delete the "no CI" trap), or
  the PHP version drift is resolved (docs corrected or XAMPP moved to 8.3).
- Sibling skills: bookly-change-control, bookly-architecture-contract,
  bookly-scheduling-domain-reference, bookly-failure-archaeology,
  bookly-debugging-playbook, bookly-run-and-operate, bookly-validation-and-qa,
  bookly-timezone-correctness-campaign, bookly-research-frontier.
