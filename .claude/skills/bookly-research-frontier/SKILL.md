---
name: bookly-research-frontier
description: >
  Open problems, technical debt, and a hypothesis-driven spike methodology for
  Bookly. Use when asked "what should we work on next", "is X worth doing",
  "what's the roadmap", "what's technical debt here", "should we add CI /
  pagination / soft deletes", or when planning a research spike before
  committing to a feature. Does not cover timezone hardening execution (that's
  `bookly-timezone-correctness-campaign`) or how to gate a change once decided
  (that's `bookly-change-control`).
---

# Bookly Research Frontier

Verified against the working tree on 2026-07-10 (commit `a7d0ed3`, 205 passing
Pest tests, PHPStan level 6 clean). This skill is a forward-looking backlog: for each open problem it
gives the shortfall, the Bookly-specific asset that makes it tractable, first
concrete steps, and a falsifiable milestone. It ends with the general
hypothesis → spike → adopt-or-retire methodology this project should use for
anything not already fully scoped.

## When NOT to use this skill

- **Timezone edge cases and DST correctness** — a dedicated campaign already
  exists with its own execution plan: `bookly-timezone-correctness-campaign`.
  This skill only flags that hardening is an open *question*; it does not own
  the remediation plan.
- **You've already decided to build something** — once a problem below is
  picked up, `bookly-change-control` owns the gates (tests-first, pint, human
  approval for schema/slug/dependency changes, README/CLAUDE.md mechanics).
  This skill stops at "here's the spike"; it does not replace the change
  workflow.
- **"Why is it built this way"** — `bookly-architecture-contract` owns the
  rationale for existing decisions. This skill only cites its "Honest weak
  points" table as raw material for what to research next.
- **A specific untested scheduling edge case** — `bookly-scheduling-domain-reference`
  §6 lists 13 untested cases owned operationally by the timezone campaign;
  this skill notes them once, at the "should we harden this at all" level.

## Open problems

Each entry: **shortfall** (why current state falls short) → **asset** (what
Bookly already has that makes this tractable) → **first steps** → **milestone**
(how you'll know the spike succeeded or should be retired).

### 1. No CI (`bookly-architecture-contract` weak point #5) — SHIPPED 2026-07-10

**Resolved**: `.github/workflows/tests.yml` runs composer install, npm build,
`pint --test`, `phpstan analyse`, and the full Pest suite on every push/PR to
`main`. Landed via PR #1 (commit `9b5930c`), validated green end-to-end on the
PR before merge, per the milestone below. No branch protection rule requiring
the check has been added yet — that's a separate, smaller follow-up if
wanted, not part of this entry's original scope.

**Shortfall (historical)**: `.github/` does not exist (verified `ls .github` →
not found). Pint and `php artisan test` run only by human convention before
commit; nothing blocks a broken commit from landing on `main`, and there are
no branches/PRs to gate in the first place (`git log --oneline` shows 10
commits, all direct to `main`).

**Asset**: 192 passing Pest tests (`php artisan test --compact` → `192 passed
(787 assertions)`, 8.56s) give a CI job something real to run and a fast
feedback loop — this is not a green-field CI setup, it's wiring up checks that
already exist and already pass locally. `phpunit.xml` needs checking before
assuming a workflow "just works": confirm it points at a file-based or
in-memory DB (sqlite) rather than requiring a live MySQL service, since CI
runners won't have XAMPP's MySQL on 3306 by default.

**First steps**:
1. `Read phpunit.xml` and check the `DB_CONNECTION`/`DB_DATABASE` env block —
   if it's not already sqlite `:memory:`, a CI workflow needs either a MySQL
   service container or a sqlite override for the test env, and that decision
   should be made explicit rather than copy-pasted from a generic Laravel
   Actions template.
2. Draft `.github/workflows/tests.yml` running, in order: `composer install`,
   `npm install && npm run build` (tests that assert on Inertia pages need the
   Vite manifest per CLAUDE.md), `vendor/bin/pint --test` (dry-run, don't
   auto-fix in CI), `php artisan test --compact`.
3. Run the workflow via `act` locally or push to a throwaway branch first —
   do not let the first real CI run be against `main`, since there are no
   branch protections yet either.

**Milestone**: a PR (or a pushed branch) shows a green GitHub Actions run
covering pint + full test suite + `npm run build`, end to end, without a human
running any command locally first. This is genuinely low-risk, high-value —
a good first spike, since it only adds automation around commands that already
pass; it doesn't touch schema, slugs, or dependencies, so it doesn't trip the
change-control approval gates (no new dependency is added — `actions/checkout`
and `shivammathur/setup-php` are GitHub-hosted actions, not project deps).

### 2. Unpaginated bookings index (weak point #10) — SHIPPED 2026-07-10

**Resolved**: `BookingController::index` now cursor-paginates `past` bookings
(`PAST_PER_PAGE = 15`, `orderByDesc('starts_at')->orderByDesc('id')` for a
deterministic tiebreak) while `upcoming` stays fully eager — the dashboard and
reminder command were verified to run their own independent bounded queries,
so they never depended on the index's old unbounded shape. `Bookings/Index.vue`
adds Older/Newer nav via a partial reload (`only: ['past']`). Test-first:
`tests/Feature/BookingPaginationTest.php` proves bounded page weight (500-row
seed still returns exactly 15), disjoint cursor pages including under tied
`starts_at`, and that `upcoming` stays a plain unpaginated array. Suite: 211
passed, pint + phpstan clean.

**Shortfall (historical)**: `BookingController::index` loaded **all** of a
host's bookings, eager-loaded `eventType`, and split upcoming/past in PHP
(`app/Http/Controllers/BookingController.php:24-39`) — `->get()` with no
`limit`/`paginate`. Fine at v1 scale (a few dozen bookings); became a real
problem once a host accumulated hundreds of historical bookings — every page
load paid for the full history, forever, even though only "upcoming" bookings
are usually acted on.

**Asset**: nobody has adopted Inertia v2 deferred/lazy-loading patterns yet —
a grep across `resources/js/Pages/**` for `WhenVisible`, `defer`, or partial
reloads turned up nothing; every page here uses plain `useForm` and full-page
`Inertia::render()`. That means there's no existing convention to reconcile
with, but also no in-repo example to copy — this is genuinely a "first of its
kind" pattern for this codebase, so the spike should produce a small, reusable
convention (e.g. how "past bookings" get paginated) rather than a one-off
fix, since other admin-style lists (event types, availability overrides) will
eventually hit the same shape.

**First steps**:
1. Decide the model: cursor pagination (`cursorPaginate()`, stable under
   concurrent inserts) vs Inertia v2 deferred props (render "upcoming"
   eagerly, defer-load "past" behind a `WhenVisible`/lazy prop) — these solve
   different problems (page weight vs perceived load time) and CLAUDE.md /
   laravel-boost-guidelines flag deferred props as an available v2 feature,
   but the codebase doesn't use them anywhere yet, so there's no existing
   empty-state skeleton convention to match — one would need to be designed
   from scratch (the guidance explicitly requires a pulsing/skeleton state for
   deferred props).
2. Prototype against `past` bookings only first (lower risk than touching
   `upcoming`, which the dashboard and reminder logic implicitly assume is
   small and complete).
3. Write a feature test asserting page size / cursor behavior before touching
   `BookingController.php`, per CLAUDE.md's tests-first rule.

**Milestone**: a host with 500+ seeded bookings loads `/bookings` in roughly
the same wall-clock time as a host with 20 — verified by a factory-seeded test
or a manual timed comparison, not by assumption. Retire the spike if cursor
pagination alone (no deferred props) gets there — don't add UI complexity the
data volume doesn't yet justify (YAGNI).

### 3. No soft deletes / cascade-delete of bookings (weak point #8) — SHIPPED 2026-07-10

**Resolved**: option (a) was chosen after a `planner` comparison of (a) prevent
deletion / (b) soft-delete / (c) both — no schema change needed. `EventTypeController::destroy`
now guards on "active" bookings (`status === Confirmed AND starts_at >= now()`,
the same boundary `BookingController::index` uses for "upcoming"), wrapped in
`DB::transaction()` + `lockForUpdate()` to close a TOCTOU race a code review
caught. Scoped narrowly: any booking that is `Cancelled`, `Completed`, `NoShow`,
or `Confirmed`-but-past no longer blocks deletion. Account deletion
(`ProfileController::destroy`) was explicitly left untouched — a deliberate
scope decision, not an oversight; it still cascade-deletes a host's bookings.
Tests: `tests/Feature/EventTypeTest.php` extended, 213 passed, pint + phpstan
clean. **Options (b) and (c) remain open** if stronger history-preservation
than "block deletion" is wanted later — see the planning research below for
why (b) as originally scoped doesn't actually work (`SoftDeletes` on `Booking`
alone doesn't survive a DB-level `ON DELETE CASCADE` from a hard-deleted
parent `EventType`/`User`).

**Shortfall (historical)**: `event_type_id` and `host_user_id` on `bookings`
are `cascadeOnDelete()` with no `softDeletes()` column
(`database/migrations/2026_06_29_063548_create_bookings_table.php`); deleting
an event type hard-deleted every booking ever made against it, with no
confirmation of booking count and no archive. A second, broader path existed
too: `ProfileController::destroy` (account deletion) cascade-deletes via
`host_user_id` — not just event-type deletion, as this entry originally
implied.

**If picking up (b) or (c) later**: read the corrected analysis above first —
(b) requires either changing `event_type_id`/`host_user_id` to `nullOnDelete()`
plus snapshotting event-type display fields onto the booking, or soft-deleting
`EventType`/`User` too (which collides with slug-uniqueness semantics, the
project's most fenced area). Either shape is a schema change requiring human
approval per `bookly-change-control` §5, and (b)/(c) both raise a retention-policy
question (forever vs. pruned after N days) that must be answered in writing
before the migration is written, not after.

### 4. Next product phase after guest self-service (scope check)

**RESOLVED 2026-07-10**: the audit-trail candidate this entry used to point at
shipped in Phase 10 (commit `3712dcd`, `booking_events` table +
`BookingActor`/`BookingEventKind` enums + `Booking::recordEvent()` + per-booking
History timeline). README's status table now reads `Next | Candidates: TBD |
Planned` — there is currently **no officially sanctioned next candidate**.
Treat problems #1–#3 and #5 above as the live open-problem list until a human
picks one (or names something new).

**Shortfall (still live)**: CLAUDE.md's "Do not" list still literally says "Do
not add guest-booking or availability logic until Phase 3 is scoped" — phases
3 through 11 are shipped, so this is stale prose (weak point #7 in the
architecture contract). The teams/multi-tenancy fence ("v1 is
single-user-per-account") is still a live, deliberate constraint, not a bug —
do not treat it as a research candidate without a human explicitly lifting it.

**First steps**:
1. Flag the CLAUDE.md Phase-3 staleness to a human as a standalone `docs:`
   fix — separate from any new feature work, since editing CLAUDE.md is
   itself a gated change (`bookly-change-control` §4).
2. When a human names the next candidate, replace this entry's shortfall/asset
   with the new one rather than appending — keep one entry per open problem.

**Milestone**: CLAUDE.md's "Do not" Phase-3 line is corrected, and this entry
names a real next candidate instead of "TBD." Do not treat "teams" or "guest
accounts" as research candidates — those are out-of-scope fences, not open
questions, until a human explicitly lifts them.

### 5. Reminder scheduler gap outside dev (weak points #1, #2; ops notes) — RUNBOOK WRITTEN 2026-07-10, LIVE VERIFICATION PENDING

**Partially resolved**: asked the human for the hosting target — undecided as
of 2026-07-10. Per this entry's own step 3, wrote `bookly-run-and-operate` §3a
as a deployment runbook covering both a VPS/cron/Supervisor shape and a
containerized shape generically, bundling the scheduler and queue-worker gaps
into one "process supervision" answer as this entry's step 2 recommended, and
flagged `MAIL_MAILER=log` as a related-but-distinct gap that process
supervision alone doesn't fix. **This entry's milestone is NOT fully met**:
the runbook documents the correct command sequence, but the "kill and restart,
confirm resume" verification has not been run against a live deployment,
because no deployment exists yet. Re-open this entry (or just do the
verification) once a hosting target is picked and a real environment exists —
at that point also specialize §3a for the actual target rather than leaving
it generic, per that section's own note.

**Shortfall**: `bootstrap/app.php:25-27` schedules `bookings:send-reminders`
`->daily()`, but Laravel's scheduler is inert without something invoking
`schedule:run` every minute (cron) or `schedule:work` as a long-running
process. `bookly-run-and-operate` already documents that nothing in
`composer run dev` starts this on Windows/XAMPP. The open research question
here is specifically the **production** deployment shape — what a hosting
environment needs — which is distinct from the dev-mode gap already
documented elsewhere.

**Asset**: the command itself is already lock-safe and idempotent (per-row
`lockForUpdate` + `reminder_sent_at` re-check —
`app/Console/Commands/SendBookingReminders.php:30-41`, cited in the
architecture contract's load-bearing decision #8), so the research question is
purely "what invokes it reliably", not "is it safe to invoke twice" — that
part is already solved.

**First steps**:
1. Confirm the actual hosting target before researching a specific mechanism
   — a cron entry (`* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`)
   is the standard answer for shared/VPS hosting, but a container or serverless
   target would need `schedule:work` as a supervised long-running process
   (systemd unit, Supervisor, or a managed scheduler) instead. Don't guess;
   ask what the deployment target is.
2. Same question applies to the queue worker gap (weak point #2 — all 6
   queued notifications go to the `database` queue and silently never send if
   `queue:work` isn't running) — bundle both into one "process supervision"
   research question rather than solving them separately, since the answer
   (systemd/Supervisor/managed platform) is likely shared infrastructure.
3. Write the answer as a deployment runbook addition to `bookly-run-and-operate`,
   not as new application code — this is an ops research question, not a
   code change.

**Milestone**: a documented, testable command sequence (or hosting-specific
config file) that a human can run once at deploy time and never think about
again — verified by intentionally killing and restarting the process and
confirming reminders/queued mail resume without manual intervention.

## Scheduling edge-case hardening (pointer, not owned here)

`bookly-scheduling-domain-reference` §6 lists 13 untested timezone/DST edge
cases. Whether and how deeply to harden each one is a legitimate research
question — worth asking "is this worth a spike" before assuming every edge
case needs code — but the actual remediation plan and priority order is owned
by `bookly-timezone-correctness-campaign`. Don't duplicate that plan here;
if asked "should we harden timezone edge cases", the answer is "yes, and
there's already a campaign for it" — point there, don't re-derive it.

## General methodology: hypothesis → spike → adopt-or-retire

Use this shape for anything above, and for any new open problem not yet
listed here:

1. **State the hypothesis as a falsifiable claim**, not a vague goal. Not "we
   should have CI" but "a GitHub Actions workflow running pint + the existing
   192 tests + `npm run build` can go green without modifying any of those
   commands." A hypothesis that can't fail isn't a spike, it's a foregone
   conclusion.
2. **Time-box the spike.** Pick a duration before starting, not after it
   feels like it's dragging. If the phpunit/CI DB question (problem #1, step
   1) turns into a multi-day yak-shave, that's a signal to retire the spike
   and re-scope, not to keep pushing.
3. **Decide adopt-or-retire explicitly**, in writing, even if the answer is
   "retire". A spike that quietly fades out without a recorded decision will
   get re-litigated by the next person who reads the weak-points table and
   doesn't know it was already tried.
4. **Route the decision through the right gate.** Adopting anything that
   touches schema (`bookings`, `event_types`), slug semantics, or adds a new
   Composer/npm dependency requires explicit human approval **before**
   implementation, per `bookly-change-control` §5 — not as a post-hoc review.
   Adopting something that's purely additive tooling (CI workflow, a new test)
   does not need that same approval gate, but still goes through the normal
   tests-first → pint → full suite workflow.
5. **Write down what "adopt" looks like operationally** before you start:
   which skill file needs updating (this one, if the problem is now solved;
   `bookly-run-and-operate` if it's an ops change; README's status table if
   it's a shipped feature), so the decision is discoverable later instead of
   living only in a chat transcript.

## Provenance and maintenance

- Authored 2026-07-06 against `C:\xampp\htdocs\bookly` @ `086f30b`. Weak
  points sourced directly from `bookly-architecture-contract`'s "Honest weak
  points" table (verified 2026-07-05, re-checked here); CLAUDE.md's "Do not"
  list and README's status table read directly for this skill (`README.md:17-29`,
  `CLAUDE.md` "Do not" section). `.github/` absence and the 192-test count
  were re-verified live on 2026-07-06 (`ls .github` → not found; `php artisan
  test --compact` → `192 passed (787 assertions)`, 8.56s). A repo-wide grep for
  `WhenVisible`/`defer` across `resources/js/Pages/**` found no existing
  Inertia v2 deferred-props usage — confirmed absent, not assumed.
- Re-verify before trusting: the README "Next" candidate row (problem #4) —
  if it's been updated since 2026-07-06, that's the actual current frontier,
  not what's written here. Re-run `php artisan test --compact` for a current
  test count before quoting "192" as a CI asset.
- If a problem below gets adopted and shipped, remove it from this skill (or
  mark it done with a pointer to the commit) — a stale "open problem" that's
  actually closed is exactly the kind of doc drift `bookly-change-control`
  warns about.
- Sibling skills: architecture rationale → `bookly-architecture-contract`;
  change gates and approval → `bookly-change-control`; timezone hardening
  execution → `bookly-timezone-correctness-campaign`; ops/deploy detail →
  `bookly-run-and-operate`; env/build traps → `bookly-build-and-env`; past
  incidents → `bookly-failure-archaeology`.
