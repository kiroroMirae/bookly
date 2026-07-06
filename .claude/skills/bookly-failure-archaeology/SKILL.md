---
name: bookly-failure-archaeology
description: >
  Institutional memory of decisions and drift in Bookly — not live debugging, not
  architecture rationale. Use when asking "isn't this a bug", "why doesn't X have a
  unique constraint", "should I fix this", "docs say X but code does Y", "has this been
  tried before", "why is there no index on...", or before touching anything on
  CLAUDE.md's Do-not list (slugs, teams, guest accounts). Distinguishes settled
  decisions a newcomer might mistake for bugs from actual unresolved doc/code drift.
---

# Bookly Failure Archaeology

Verified against `C:\xampp\htdocs\bookly` @ `086f30b` on 2026-07-06. This skill is a
memory of *what was decided and what has drifted* — it does not diagnose a live bug
and it does not re-derive the WHY of the core architecture rules (that's
`bookly-architecture-contract`, which this skill leans on and cross-references
rather than duplicates).

## When NOT to use this skill

| You actually want | Go to |
|---|---|
| A bug is happening right now, step-by-step diagnosis | `bookly-debugging-playbook` |
| Deep rationale for the 5 architecture rules / request flow tracing | `bookly-architecture-contract` |
| Timezone/DST correctness work-in-progress | `bookly-timezone-correctness-campaign` |
| How to classify and gate a change, commit style | `bookly-change-control` |
| Slot math, ICS internals | `bookly-scheduling-domain-reference` |
| Env/build setup, ops/runbook | `bookly-build-and-env`, `bookly-run-and-operate` |

## How to read this skill

Three logs, each entry format-consistent:

1. **Settled decisions** — things that look wrong to a fresh reader but are
   intentional. Each says what a newcomer would be tempted to "fix" and why not to,
   without human sign-off per `bookly-change-control` §5.
2. **Doc/code drift log** — places the docs and the code disagree. Stated as
   claim → reality → verified date, so the next person doesn't have to re-derive it.
3. **Fenced dead ends** — explicit no-go zones from `CLAUDE.md`, with the rationale
   pointer.

## 1. Settled decisions that look like bugs

### 1.1 No unique constraint on `(host_user_id, starts_at)`

- **Looks like**: a glaring omission — how is double-booking actually prevented?
- **Evidence it's intentional**: `database/migrations/2026_06_29_063548_create_bookings_table.php:29`
  has only `$table->index(['host_user_id', 'starts_at'])` — a plain lookup index, not
  `unique()`. Double-booking protection is instead enforced at the application layer:
  `DB::transaction` + `lockForUpdate()` over all the host's booking rows, followed by
  an in-transaction `SlotGenerator::forDate()` recheck, in
  `PublicBookingController.php:63-89`, `GuestBookingController.php:~90-112`, and
  `BookingController.php:~89-111` (three independent copies of the same pattern).
- **Why not just add the constraint**: a DB unique constraint can't express "is this
  slot free" — that depends on buffers, min-notice, daily caps, and overrides, all of
  which are computed, not stored per-row. A unique index on `starts_at` alone would
  also be wrong because two *different* event types with different durations could
  legitimately abut without literally sharing a `starts_at`. This is
  `bookly-architecture-contract` load-bearing decision #6 / weak point #4 — the
  lock-then-recheck IS the correctness mechanism, by design, not a stand-in for one.
- **What a newcomer should NOT do without approval**: add `unique(['host_user_id',
  'starts_at'])` "to be safe". It would reject legitimate non-conflicting bookings
  (e.g., two 15-minute event types with the same start after a cap/override
  recalculation) and would not even fix the theoretical race, since the real
  invariant is buffer-aware overlap, not exact-timestamp equality. Per
  `bookly-change-control` §1, schema changes to `bookings` need human sign-off
  regardless.

### 1.2 Guest signed URLs never expire

- **Looks like**: a security bug — a leaked email link from six months ago should
  stop working.
- **Evidence it's intentional**: `GuestBookingController.php:142-149` and
  `app/Notifications/GuestBookingConfirmed.php:29-33` /
  `GuestBookingRescheduled.php:29` all call `URL::signedRoute(...)`, never
  `temporarySignedRoute(...)`. This is a deliberate choice, not an oversight —
  `bookly-architecture-contract` weak point #9 labels it **accepted-for-v1**: guests
  have no accounts (load-bearing decision #2), so a signed link is the only handle
  they ever get on their own booking, potentially long after the event. An expiring
  link would lock a guest out of viewing/canceling a legitimate future booking if
  they don't act within some arbitrary window.
- **The actual guard is state-based, not time-based**: `canModify()`
  (`GuestBookingController.php:136-140`) requires `status === Confirmed` AND
  `starts_at` still in the future. A cancelled or past booking is already read-only
  regardless of URL age.
- **What a newcomer should NOT do without approval**: swap to
  `temporarySignedRoute()` "to reduce exposure window" — this changes guest-facing
  behavior (old links break) and is exactly the kind of guest-experience contract
  change `bookly-change-control` §5 requires sign-off for.

### 1.3 `GuestBookingReminder` is the only notification NOT `ShouldQueue`

- **Looks like**: an inconsistency bug — 6 of 7 notification classes implement
  `ShouldQueue`, this one doesn't; looks like someone forgot the interface.
- **Evidence it's intentional-by-consequence, not accidental**: confirmed by reading
  `app/Notifications/GuestBookingReminder.php` in full — no `implements ShouldQueue`,
  no `use Queueable`. It is dispatched from inside
  `SendBookingReminders.php:30-41`'s `DB::transaction` + `lockForUpdate()` block,
  where the row's `reminder_sent_at` is set only after the notification is sent. If
  it were queued, the transaction would commit (marking the reminder "sent")
  *before* the queued job actually delivers it — and a failed queue job would leave
  `reminder_sent_at` set with no reminder ever having gone out. Sending synchronously
  inside the lock means a send failure rolls back the whole transaction, so
  `reminder_sent_at` stays null and a future run retries it. `bookly-architecture-contract`
  weak point #3 calls this **accepted-for-v1, inconsistent** — the inconsistency is
  real (slow SMTP holds a row lock) but the fix isn't "just add ShouldQueue", it's
  redesigning the sent-tracking to be safe under async delivery (e.g. a
  `reminder_queued_at` / job-callback pattern) — a bigger change than it looks.
- **What a newcomer should NOT do without approval**: add `ShouldQueue` to make it
  "consistent" with the other six — this reintroduces the double-send/lost-send race
  the synchronous design avoids.

### 1.4 Host bookings index (`/bookings`) is unpaginated

- **Looks like**: a scaling bug waiting to happen.
- **Evidence it's intentional-for-now**: `BookingController.php:24-38` loads
  `$host->bookings()` in full, eager-loads `eventType`, and splits upcoming/past in
  PHP. `bookly-architecture-contract` weak point #10 labels this
  **accepted-for-v1, revisit** — v1 has no pagination UI component and no host is
  expected to accumulate thousands of bookings yet. It is a real scaling ceiling, not
  a design principle, so it's fine to raise as a future phase — just don't silently
  bolt on pagination mid-unrelated-change; it touches a shared Vue page and its tests.

### 1.5 `bookings.status` is a plain string, not a DB enum/check constraint

- **Looks like**: a data-integrity gap — nothing stops `UPDATE bookings SET
  status='bogus'` at the DB layer.
- **Evidence it's intentional**: `create_bookings_table.php` defines `status` as a
  plain string column defaulting to `'confirmed'`; the only guard is the PHP-side
  `BookingStatus` enum cast on the model. `bookly-architecture-contract` weak point
  #12, **accepted-for-v1** — MySQL enum columns are painful to alter (`ALTER TABLE`
  rewrites), and application-level guarding (Form Requests whitelist values, e.g.
  `UpdateBookingRequest` restricts to `['completed','no_show']`) is judged sufficient
  for a single-writer app with no raw SQL access.

## 2. Doc/code drift log

Each entry: claim → reality → verified date. Report drift when found; do not
silently "fix" docs per `bookly-change-control` §4 without approval, since editing
CLAUDE.md is itself a gated change.

| # | Claim | Reality | Verified |
|---|---|---|---|
| 1 | `CLAUDE.md:7` and `README.md:33` state "PHP 8.3" (also repeated in the `<laravel-boost-guidelines>` block, `CLAUDE.md:64`) | Local XAMPP CLI is **PHP 8.2.12** (`php -v`); `composer.json:9` requires only `"php": "^8.2"`, so nothing is actually broken by this — the docs overstate the platform | 2026-07-06 (re-run `php -v`; unchanged since `bookly-build-and-env`'s 2026-07-05 finding) |
| 2 | "Is the reminder command scheduled?" — `routes/console.php` contains only the stock `inspire` closure, which reads as "nothing is scheduled here" | It IS scheduled: `bootstrap/app.php:25-27` registers `->withSchedule(fn (Schedule $s) => $s->command(SendBookingReminders::class)->daily())` — Laravel 12 moved this out of `routes/console.php`. `README.md:64`'s "scheduled daily" claim is **true at the code level**. The real gap is operational: nothing runs `schedule:work` in dev, so the scheduled job never fires without a human starting it (`bookly-run-and-operate` §3) | 2026-07-06 (re-confirmed against `bootstrap/app.php`; matches `bookly-run-and-operate`'s note that an *earlier* belief — "unscheduled" — was wrong and was corrected in that same skill) |
| 3 | `CLAUDE.md:44-49` ("Do not") still says: "Do not add guest-booking or availability logic until Phase 3 is scoped." Its "Key domain concepts" section (`CLAUDE.md:13-17`) marks Booking and Availability as "(Phase 3+)" as if still pending | Phases 3 through 9 are shipped and merged to `main` (`README.md` status table; `git log` shows `feat: Phase 3 & 4`, `Phase 5`...`Phase 9`, culminating in `086f30b`). Guest booking, availability windows, availability overrides, booking policies, ICS invites, and the ICS subscribe feed all exist and are tested. `CLAUDE.md` was never updated past its Phase-1/2 scaffolding state | 2026-07-06 (cross-checked `CLAUDE.md` line-by-line against `README.md` status table and `git log --oneline`) |
| 4 | (Not a drift, confirmed accurate) `.claude/checkpoints.log` should have one entry per phase | File has only 3 entries (phases 1, 2, 4) though git shows phases through 9 — checkpoint discipline lapsed after phase 4 and was never resumed. This is real, not a stale claim; already flagged in `bookly-change-control` §3 step 7. Listed here only so a future audit doesn't waste time re-finding it | 2026-07-06 (`Get-Content .claude\checkpoints.log`) |

### On drift item 3 specifically

This is the widest gap found. `CLAUDE.md` is the "doc of record" for internal rules
per `bookly-change-control` §4, and it materially misdescribes the current state of
the codebase to any agent that reads it literally — an agent following `CLAUDE.md`'s
"Do not" list at face value would refuse to touch files that have been shipped,
tested, and in production-shape for 6 of the project's 9 phases. Treat `CLAUDE.md`'s
"Key domain concepts" and "Do not" §3 line as **stale, not authoritative** — verify
against `README.md`'s status table and the actual `app/` tree first. This is already
recorded as `bookly-architecture-contract` weak point #7; this entry exists so the
archaeology log doesn't miss it too.

## 3. Fenced dead ends (do not attempt without explicit human approval)

Straight from `CLAUDE.md:44-49`'s "Do not" list, with the rationale pointer into
`bookly-architecture-contract`:

1. **"Do not modify slugs on update — they are set once at creation."**
   (`CLAUDE.md:46`) — rationale: booking URLs (`/{username}/{slug}`) are shared in
   guest emails and bookmarks forever; regenerating breaks every link ever sent.
   Full mechanics in `bookly-architecture-contract` load-bearing decision #1. The
   enforcement is by omission — `UpdateEventTypeRequest` simply has no `slug` field
   (`app/Http/Requests/UpdateEventTypeRequest.php:16-30`) — so "fixing" a perceived
   gap by adding one back is the single most common way this rule gets silently
   broken. Don't.

2. **"Do not store secrets in code; keys live in `.env`."** (`CLAUDE.md:47`) —
   standard secret-management discipline; no project-specific exception exists.
   `calendar_feed_token` (per-user, DB-stored, 64-char random) is not a code secret
   and is explicitly designed to be a lookup key, not an app credential — don't
   confuse the two when reasoning about this rule.

3. **"Do not add guest-booking or availability logic until Phase 3 is scoped."**
   (`CLAUDE.md:48`) — this is drift item 3 above: Phase 3+ already shipped. The
   sentence is now meaningless as a forward-looking constraint, but the *spirit* of
   it (new booking/availability features get scoped and tested deliberately, not
   bolted on ad hoc) still applies — read it as "don't add booking-domain logic
   without a Pest test and a phase-shaped change", not "don't touch Booking at all".

4. **"Do not introduce teams or multi-tenant models — v1 is single-user-per-account."**
   (`CLAUDE.md:49`) — rationale in `bookly-architecture-contract`: authorization is
   built entirely on `Gate::authorize()` + three policies doing pure `user->id ===
   owner_id` checks (load-bearing decision #3). Every route-model-bound resource
   assumes exactly one owning user. Introducing teams would require touching all
   three policies, the slug-uniqueness scope (`unique(['user_id','slug'])` would need
   to become `unique(['team_id','slug'])` or similar), and every notification
   fan-out — this is a foundational rewrite, not an additive feature, and is
   explicitly out of scope per `bookly-change-control` §5.

No other "Do not" items exist in `CLAUDE.md` as of 2026-07-06 — the list is exactly
these four lines (`CLAUDE.md:46-49`).

## 4. Git history read — result: nothing hidden

Read directly (not copied from a sibling skill) via `git log --oneline --all`,
`git log --oneline --stat --all`, `git reflog`, and `git branch -a` on 2026-07-06:

- **10 commits total**, `c9d9abf`..`086f30b`, all on `main`, no other local or
  remote branches (`origin/main` only).
- **No merge commits, no reverts, no `--amend` traces, no force-push markers** in the
  reflog — the reflog shows exactly the 10 commits plus one `Branch: renamed
  refs/heads/master to refs/heads/main` entry (a one-time repo-init rename, not a
  content event).
- **No redone/renumbered phases**: phase commits are strictly sequential — Phase 1,
  Phase 2, "Phase 3 & 4" (combined in one commit, not two, per the actual subject
  line `feat: Phase 3 & 4 — availability, public booking, notifications`), a `chore:`
  commit for storage scaffolding + README between phases 4 and 6, then Phase 6
  through Phase 9 each as its own commit, then a `docs:` commit. Nothing skips a
  number or repeats one.
- **Conclusion**: there is no hidden history to recover here. This matches what
  `bookly-change-control` already documents (10 commits, main only, no CI) — this
  section exists to confirm that claim was independently re-verified against live
  `git` output, not carried forward unchecked from a sibling skill that may itself
  have drifted since 2026-07-05.

If a future re-read of this section finds more commits, a second branch, or evidence
of `--amend`/force-push (e.g. reflog entries with a `rebase` or `reset` action, or a
recreated commit sha for an existing phase), **update this section with the new
finding and date it** — do not assume this section is evergreen.

## Provenance and maintenance

- Authored 2026-07-06 by direct inspection: `database/migrations/2026_06_29_063548_create_bookings_table.php`,
  `GuestBookingController.php`, `app/Notifications/GuestBookingConfirmed.php`,
  `GuestBookingRescheduled.php`, `app/Notifications/GuestBookingReminder.php` (read in
  full), `SendBookingReminders.php`, `BookingController.php`
  (`destroy`/index/eager-load), `create_bookings_table.php` (status column), `CLAUDE.md`
  (full file), `README.md` status table, `bootstrap/app.php`, `.claude/checkpoints.log`,
  and live `git log --oneline --stat --all` / `git reflog` / `git branch -a` /
  `php artisan test --compact` (192 passed, 787 assertions, 2026-07-06).
- Every claim in this skill was re-verified against the current working tree rather
  than copied from `bookly-architecture-contract`, `bookly-build-and-env`,
  `bookly-change-control`, or `bookly-run-and-operate` — those skills are cited by
  name where their prior findings matched, and this skill notes explicitly wherever
  it independently re-ran the check (git history, test count, PHP version).
- Re-verify triggers: any new migration touching `bookings`/`event_types` (§1), any
  change to notification `ShouldQueue` usage (§1.3), any edit to `CLAUDE.md`'s "Do
  not" list or "Key domain concepts" (§2, §3 — and if `CLAUDE.md` is corrected, retire
  drift item 3 rather than leaving it to rot in this log), and any new commit that
  looks like a revert/redo (§4 — re-run `git log --oneline --stat --all` and
  `git reflog`).
- Sibling skills: `bookly-architecture-contract` (WHY of the 5 rules, weak-points
  table this skill draws evidence from), `bookly-change-control` (gates and approval
  process referenced throughout §1 and §3), `bookly-build-and-env` /
  `bookly-run-and-operate` (PHP-version and scheduler drift, cited in §2),
  `bookly-scheduling-domain-reference` (untested edge cases — a different kind of
  "known gap" than the settled decisions here), `bookly-debugging-playbook` (live
  bugs), `bookly-timezone-correctness-campaign` (active remediation work, distinct
  from the archaeology recorded here).
