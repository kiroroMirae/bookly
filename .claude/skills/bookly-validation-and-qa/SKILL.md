---
name: bookly-validation-and-qa
description: What "done" means for a change in Bookly, the Pest test suite inventory, how to add a new test correctly, and the current baseline pass count. Use for "how do I test this", "what does done mean", "add a test for X", "is this covered", "php artisan test", "write a regression test", or "do I need a Feature test for this controller action".
---

# Bookly validation and QA

Ground truth about testing in this repo: what exists, what's missing, and the
concrete bar a change must clear before it's "done." Verified 2026-07-06 by
actually running the suite and reading every test file — this is not
extrapolated from CLAUDE.md alone.

## When NOT to use this skill

- Architectural invariants (slugs, locking, policies, status lifecycle) →
  `bookly-architecture-contract`.
- Scheduling-specific edge cases and the SlotGenerator/locking test map →
  `bookly-scheduling-domain-reference` (sections 5–6 are the authoritative
  edge-case coverage map for the scheduling core specifically; this skill
  does not repeat them).
- Env vars, phpunit.xml overrides, Vite manifest trap → `bookly-build-and-env`
  (section 3 documents the exact test-env config; this skill only links to it).
- The change workflow itself (order of steps: tests → pint → build → commit) →
  `bookly-change-control`.
- Debugging a failing test or reproducing a bug interactively →
  `bookly-debugging-playbook` (sibling skill, authored separately).
- Running the app manually / seeding data → `bookly-run-and-operate`.

## 1. Current baseline (verified 2026-07-06)

Ran from `C:\xampp\htdocs\bookly`:

```
php artisan test --compact
```

Result: **192 passed (787 assertions)**, duration **9.07s** on this run.

This matches the pass/assertion count from an earlier session dated
2026-07-06 (192 passed / 787 assertions), so the suite is stable and
deterministic. The duration differs — that earlier run reported ~49s,
this run completed in ~9s. Treat the assertion/pass counts as the reliable
baseline signal; wall-clock duration varies with machine load (XAMPP MySQL
running, disk cache state, etc.) and is not a meaningful regression signal
on its own. If a future run reports fewer than 192 passed, something broke —
investigate before proceeding.

Re-run yourself before trusting this number on a fresh session; test counts
drift as phases are added.

## 2. Test suite inventory (as of 2026-07-06)

All 192 tests live under `tests/Feature/` (21 files) and `tests/Unit/`
(3 files). Grouped by domain — file, then what it actually covers (verified
by reading each file, not inferred from the name alone):

### EventTypes
- `tests/Feature/EventTypeTest.php` (219 lines) — full CRUD, per-user slug
  scoping, ownership 403s, active/inactive toggle, validation errors.

### Availability (weekly windows + overrides)
- `tests/Feature/AvailabilityTest.php` (125 lines) — weekly window edit/update,
  ownership.
- `tests/Feature/AvailabilityOverrideTest.php` (146 lines) — date-specific
  override store/destroy, ownership, validation.

### Bookings (host-side management)
- `tests/Feature/BookingTest.php` (179 lines) — booking model/creation-path
  behavior.
- `tests/Feature/BookingManagementTest.php` (103 lines) — index, filtering.
- `tests/Feature/HostBookingManageTest.php` (208 lines) — host update/cancel/
  reschedule actions on `BookingController`, ownership 403s.

### Public booking (guest-facing, no account)
- `tests/Feature/PublicBookingTest.php` (105 lines) — public show/store/
  confirmation on `PublicBookingController`.

### Guest self-service (signed URLs)
- `tests/Feature/GuestBookingManageTest.php` (246 lines) — `GuestBookingController`
  show/cancel/reschedule behind `signed:relative` middleware, signature
  tampering / expiry cases.

### Calendar feed (ICS subscribe, Phase 9)
- `tests/Feature/CalendarFeedTest.php` (180 lines) — `CalendarFeedController`
  token regenerate + `.ics` show endpoint (public, signed by opaque token
  not Laravel's `signed` middleware — see architecture-contract for why).
- `tests/Unit/IcsGeneratorTest.php` (253 lines) — `app/Services/IcsGenerator.php`
  VEVENT formatting, escaping, timezone handling.

### Scheduling core (unit level)
- `tests/Unit/SlotGeneratorTest.php` (387 lines) — the slot generation
  pipeline; see `bookly-scheduling-domain-reference` §5–6 for the exact
  edge-case list this file does and doesn't pin.

### Notifications / reminders
- `tests/Feature/NotificationTest.php` (162 lines) — booking-related
  Mailables/Notifications fire on the right events (Phase 8 ICS invites
  included).
- `tests/Feature/ReminderCommandTest.php` (81 lines) — the scheduled reminder
  Artisan command.

### Dashboard / Profile / Auth
- `tests/Feature/DashboardTest.php` (77 lines) — `DashboardController`.
- `tests/Feature/ProfileTest.php` (99 lines) — `ProfileController` (Breeze
  default, largely unmodified).
- `tests/Feature/Auth/*.php` (6 files, 313 lines total) — Breeze scaffolding:
  Authentication, EmailVerification, PasswordConfirmation, PasswordReset,
  PasswordUpdate, Registration. Stock Breeze coverage, not Bookly-specific.

### Schema / scaffolding
- `tests/Feature/Phase1SchemaTest.php` (113 lines) — early migration/column
  existence checks from Phase 1.
- `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` — Laravel
  default scaffolding, no real assertions about Bookly behavior.

### Controller-to-test coverage check

Every controller in `app/Http/Controllers/` has at least one corresponding
Feature test file (checked 2026-07-06 by cross-referencing
`find app/Http/Controllers -name "*.php"` against the list above):

| Controller | Test file(s) |
|---|---|
| `EventTypeController` | `EventTypeTest.php` |
| `AvailabilityController` | `AvailabilityTest.php` |
| `AvailabilityOverrideController` | `AvailabilityOverrideTest.php` |
| `BookingController` | `BookingTest.php`, `BookingManagementTest.php`, `HostBookingManageTest.php` |
| `PublicBookingController` | `PublicBookingTest.php` |
| `GuestBookingController` | `GuestBookingManageTest.php` |
| `CalendarFeedController` | `CalendarFeedTest.php` |
| `DashboardController` | `DashboardTest.php` |
| `ProfileController` | `ProfileTest.php` |
| `Auth/*` (9 controllers) | `tests/Feature/Auth/*` (Breeze default) |

No controller is untested at the "does a Feature test exist" level. This
does **not** mean every branch/edge case inside each controller is covered —
see `bookly-scheduling-domain-reference` §6 for the honest list of untested
scheduling edge cases specifically. This skill only verifies file-level
existence, not branch coverage (see §5 below on why branch coverage isn't
tracked at all).

## 3. The "done" bar for a change in this repo

Two source citations back this, both already in `CLAUDE.md`:

- `CLAUDE.md` line 31 (project instructions): "Pest feature tests REQUIRED
  for every controller action; write tests before implementation."
- `CLAUDE.md` laravel-boost `pest/core` guidelines block: "Every change must
  be programmatically tested."

Concrete gate, by change type:

| Change type | What's required |
|---|---|
| New controller action | A Feature test hitting the route, asserting the response (Inertia component + props, redirect, or JSON), per CLAUDE.md line 31. |
| New/changed policy rule | A test proving 403 for a non-owner — this is the same requirement `bookly-change-control`'s "Authorization" row states: "Feature test proving 403 for non-owner." Do not skip this even if the happy path is tested. |
| Bug fix | A regression test that fails against the old code and passes against the fix. If you can't show it would have caught the bug, it isn't a regression test, it's decoration. |
| New Vue page | Feature test asserting `assertInertia(fn ($page) => $page->component('Foo/Bar'))` — but see §4, `npm run build` must run first or the test throws `ViteException`. |
| Schema/migration change | New migration (never edit a committed one — CLAUDE.md line 35), factory updated, existing tests still pass. |
| Docs-only change | No test gate — per `bookly-change-control`'s "Docs-only" row. |

No PHPUnit-legacy (`extends TestCase` with `public function test...()`) tests
should be introduced. Verified by sampling `tests/Feature/EventTypeTest.php`:
it uses `it('...', function () { ... })` Pest closures with `uses(RefreshDatabase::class)`
at the top — pure Pest v3 syntax throughout. Follow this style, not PHPUnit
class-based tests, for anything new.

## 4. How to add a test correctly here

1. **Scaffold it**: `php artisan make:test --pest {Name} --no-interaction`
   (per CLAUDE.md's laravel-boost `pest/core` guidance — the `{name}`
   argument should not include the test suite directory, e.g. `EventTypeTest`
   not `Feature/EventTypeTest`). Choose `--unit` only for pure-logic classes
   like `SlotGenerator`/`IcsGenerator`; everything hitting a route/controller
   is a Feature test.
2. **Use factories, not manual inserts.** Verified factories exist for every
   core model (`database/factories/`): `UserFactory`, `EventTypeFactory`,
   `BookingFactory`, `AvailabilityWindowFactory`, `AvailabilityOverrideFactory`.
   There is no `AvailabilityOverride` model confusion — the factory name
   matches the model exactly.
3. **`RefreshDatabase` + sqlite `:memory:`** — every existing Feature test
   calls `uses(RefreshDatabase::class);` at file top. The DB itself is
   sqlite in-memory per `phpunit.xml` (`DB_CONNECTION=sqlite`,
   `DB_DATABASE=:memory:`) — full override list and rationale already
   documented in `bookly-build-and-env` §3 ("Test environment (phpunit.xml)").
   Confirmed unchanged as of 2026-07-06 — no drift from that skill's table.
4. **New Vue page → `npm run build` before running tests.** If a test
   asserts against an Inertia page component that doesn't exist in the
   Vite manifest yet, it throws `Illuminate\Foundation\ViteException:
   Unable to locate file in Vite manifest`. Full explanation of why this
   happens lives in `bookly-build-and-env` — don't re-derive it here, just
   remember to build before testing.
5. **Run only what you touched, then the full suite before calling it done**:
   ```
   php artisan test --compact --filter=EventType
   php artisan test --compact
   ```
6. **Format after, not before**: `vendor/bin/pint --dirty` — this is a
   separate gate from tests, run on the PHP files you changed.

## 5. Coverage tooling: not configured (verified, not assumed)

There is **no code-coverage tooling configured in this repo**. Checked and
confirmed absent as of 2026-07-06:

- No `coverage` element or `<report>` block in `phpunit.xml` (grepped for
  `coverage` — no matches).
- No Xdebug or PCOV listed as a Composer dependency (`composer show` has no
  `pcov/*` or `xdebug` entries) — meaning `php artisan test --coverage` would
  fail here without installing one first.
- No `.github/` directory at all — there is no CI pipeline enforcing a
  coverage threshold, or any CI at all, on this repo currently.

**Do not claim an 80%-coverage bar applies to this repo.** That number
appears in the user's global `~/.claude/rules/common/testing.md`, but it is
a personal/global rule, not something this repo enforces or has tooling for.
The actual, repo-verified bar is qualitative and file-existence-based (§3
above): every controller action gets a Feature test, every policy rule gets
a 403 test, every bug fix gets a regression test. There is no percentage
number to hit, and no tool here that could measure one if you tried.

If coverage tooling is ever added to this repo (Xdebug/PCOV install, a
`--coverage` CI step, a `.github/workflows/*.yml` file), update this section
immediately — its accuracy depends on that absence remaining true.

## Provenance and maintenance

Verified 2026-07-06 by:
```
php artisan test --compact                          # 192 passed, 787 assertions
ls database/factories/                               # factory inventory
ls tests/Feature/ tests/Feature/Auth/ tests/Unit/     # test file inventory
wc -l tests/Feature/*.php tests/Unit/*.php            # size map used in §2
find app/Http/Controllers -name "*.php"               # controller list, cross-checked against §2 table
grep -n "coverage" phpunit.xml composer.json          # confirmed absent, §5
composer show | grep -iE "pcov|xdebug"                # confirmed absent, §5
find .github -type f                                  # confirmed no CI, §5
head -30 tests/Feature/EventTypeTest.php              # confirmed Pest v3 closure syntax, §3
```

Re-run the same commands to refresh this skill after any phase adds tests,
controllers, or CI. If the pass count changes, update §1. If a new
controller ships without a same-PR test file, that's a violation of §3 —
flag it, don't silently update the table to match.
