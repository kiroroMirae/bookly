---
name: bookly-change-control
description: >
  How changes are classified, gated, documented, and committed in the Bookly repo.
  Use before you commit, add a migration, change the slug behavior or slug semantics,
  edit CLAUDE.md or README.md, start a new feature phase, add a Vue page, or write a
  checkpoint log entry. Covers the change classification table, the 5 non-negotiable
  architecture rules, the tests-first workflow, commit-message house style, README
  status table mechanics, and what never ships without human approval.
---

# Bookly Change Control

How to classify a change, which gates apply, and how to record it (docs, checkpoint,
commit). Bookly is a Calendly-style scheduling SaaS: Laravel 12 + Inertia v2 + Vue 3,
Pest v3, developed on Windows + XAMPP. Repo root: `C:\xampp\htdocs\bookly`.

**Jargon:** a "phase" is one shippable feature increment, committed as a single
`feat: Phase N — …` commit (see git history). A "gate" is a check that must pass
before commit. "Doc of record" = CLAUDE.md for internal rules, README.md for public
status/features — nothing else.

## When NOT to use this skill

| You actually want | Go to |
|---|---|
| WHY the architecture rules exist (deep rationale) | `bookly-architecture-contract` |
| Slot generation, buffers, overrides, ICS internals | `bookly-scheduling-domain-reference` |
| A bug is happening right now | `bookly-debugging-playbook` |
| Past incidents and their fixes | `bookly-failure-archaeology` |
| Env setup, XAMPP, .env keys, Vite | `bookly-build-and-env` |
| Running/serving/queue/scheduler operation | `bookly-run-and-operate` |
| Test-writing patterns and QA depth | `bookly-validation-and-qa` (Pest syntax: `pest-testing`) |
| Timezone correctness work | `bookly-timezone-correctness-campaign` |
| Generic Laravel/Pest/Inertia/Tailwind patterns | `laravel-best-practices`, `pest-testing`, `inertia-vue-development`, `tailwindcss-development` |

## 1. Change classification and gates

Classify every change into exactly one primary class. Run ALL gates for that class.

| Class | Examples | Gates (in order) |
|---|---|---|
| **Schema / migration** | new column, new table, index change | Never edit a committed migration (CLAUDE.md:35) — add a NEW one via `php artisan make:migration --no-interaction`. Schema changes to `bookings` or `event_types` need human approval first. Update factories. Tests → pint → full test run. |
| **Slug-related** | anything touching `EventType.slug`, booking URLs | STOP — most fenced area, see §2 rules 1–2. Any semantic change (global uniqueness, mutability, URL shape) requires explicit human approval. Code anchors below. Tests covering per-user uniqueness + immutability required. |
| **Authorization** | who can edit/cancel/see what | Policies only (`app/Policies/` — `EventTypePolicy`, `BookingPolicy`, `AvailabilityOverridePolicy`), enforced via `Gate::authorize()` in controllers (e.g. `app/Http/Controllers/EventTypeController.php:46,55,64`). Never inline `user_id` checks. Feature test proving 403 for non-owner. |
| **New feature phase** | Phase N scope | Tests FIRST (CLAUDE.md:31), implement, pint, full `php artisan test --compact`, `npm run build` if Vue pages added, README status row, checkpoint log line, `feat: Phase N — …` commit (+ separate `docs:` commit if README updated after). |
| **Vue page addition** | new file under `resources/js/Pages/` | PascalCase resource subdirectory (CLAUDE.md:25). `npm run build` is REQUIRED before tests — the Vite manifest must include the new page or feature tests throw `ViteException` (CLAUDE.md:33). |
| **Docs-only** | README/CLAUDE.md wording | No pint/test gates. Verify claims against code first (see §4 drift examples). Commit as `docs: …`. |

Multi-class changes (e.g. a phase that adds a migration): apply the union of gates;
migration and slug gates always win conflicts.

## 2. The 5 CLAUDE.md rules (CLAUDE.md:19-25), restated

One-line rationale each; the deep WHY lives in `bookly-architecture-contract`.

1. **Slug uniqueness is per-user, not global** — the public URL is
   `/{username}/{slug}`, so the username already namespaces it; DB enforces
   `unique(['user_id', 'slug'])` (`database/migrations/2026_06_29_063547_create_event_types_table.php:25`).
2. **Slugs are immutable after creation** — shared booking links must never break.
   Enforced by omission: `uniqueSlug()` runs only in `store()`
   (`app/Http/Controllers/EventTypeController.php:37,71-88`) and
   `UpdateEventTypeRequest::rules()` has no `slug` key
   (`app/Http/Requests/UpdateEventTypeRequest.php:16-30`). Adding `slug` to that
   rules array is the classic way to break this rule silently — don't.
3. **Authorization via Policies + `Gate::authorize()`** — one place per model for
   ownership logic; policies are auto-discovered, no manual registration.
4. **Form Requests for all HTTP validation, `$request->validated()` only** — never
   `$request->all()`, never inline `validate()` in controllers
   (all requests in `app/Http/Requests/`).
5. **Inertia pages in `resources/js/Pages/`, PascalCase subdir per resource** —
   e.g. `EventTypes/Index.vue`; server routes render them via `Inertia::render()`.

## 3. Standard change workflow (every code change)

```powershell
cd C:\xampp\htdocs\bookly

# 1. Write/extend the Pest feature test FIRST (CLAUDE.md:31), watch it fail
php artisan test --compact --filter=EventType

# 2. Implement until green

# 3. Format (mandatory after any PHP change, CLAUDE.md:32)
vendor/bin/pint --dirty

# 4. Full suite
php artisan test --compact

# 5. Only if new Vue pages were added (CLAUDE.md:33)
npm run build
```

Then, **only if this completes a phase**:

6. Update the README status table row (see §4).
7. Append a checkpoint line to `.claude\checkpoints.log`. Exact format, derived from
   the real file (`.claude/checkpoints.log:1-3`):

   ```
   YYYY-MM-DD-HH:MM | label | shortsha
   ```

   Real example: `2026-06-30-10:10 | phase-2-complete | 9808192`. Label convention is
   `phase-N-complete` (first entry used `bookly-phase-1-scaffold-schema`). The sha is
   the commit you just made, so commit first, then append. Note (2026-07-05): the log
   has only 3 entries (phases 1, 2, 4) while git shows phases through 9 — checkpoint
   discipline lapsed after phase 4. Resume it; do not backfill history silently.

8. Commit. House style, derived from actual history (`git log --oneline`,
   c9d9abf..086f30b, 10 commits, `main` only, no branches/PRs/CI):

   - `feat: Phase N — description` (em dash, capital "Phase") for phase commits
   - `docs: …` for documentation (e.g. 086f30b "docs: record Phase 9 in README status table")
   - `chore: …` for scaffolding (e.g. 593bf5a)
   - Body: hyphen-bulleted list of concrete deliverables (see `git log -2 --format=%B`
     for the Phase 8/9 exemplars — mention new columns, routes, test counts)

9. Run `/learn-eval` (lesson extraction). Ask: did this phase teach anything a future
   session wouldn't rediscover from the code alone — a trap, a wrong first assumption,
   a gate that saved us? If yes, save it project-scoped (a `bookly-*` skill or
   `.claude/` note); if the same lesson has now appeared in a **second** project,
   promote it to the global layer per `~/.claude/rules/learning-loop.md`. If nothing
   was learned, say so and skip — no filler lessons.

There is **no CI** (no `.github/` directory exists as of 2026-07-05), so the local
pint + test run IS the gate. Nothing else will catch you.

## 4. Docs mechanics: CLAUDE.md vs README.md

These are the only two docs of record. One home per fact.

| | CLAUDE.md | README.md |
|---|---|---|
| Audience | Claude / engineers working in-repo | Public / newcomers |
| Owns | Architecture rules, conventions, commands, Do-not list, stack pins | Feature list, phase Status table, setup, dev/test quickstart |
| MUST update when | A convention/rule changes or a new "Do not" is agreed (human-approved edit — never unilaterally) | A phase completes (add/flip its Status row, add Features bullet) — same session as the phase, as its own `docs:` commit or inside the phase commit |

**Editing CLAUDE.md is itself a gated change**: it changes how all future work is
judged. Propose the diff, get human approval, commit as `docs:`. Never edit the
`<laravel-boost-guidelines>` block (CLAUDE.md:53+) — it is tool-managed.

**Known doc drift (live examples — report, don't silently fix):**

1. **PHP version**: CLAUDE.md:7,64 and README.md:33 say "PHP 8.3", but local PHP is
   **8.2.12** (`php -v`, verified 2026-07-05) and `composer.json:9` requires only
   `"php": "^8.2"`. The docs overstate the platform. Flag to the human; correcting
   it is a one-line `docs:` commit once approved.
2. **Reminder scheduling**: README.md:64 says `bookings:send-reminders` is
   "scheduled daily". `routes/console.php` contains only the `inspire` command
   (routes/console.php:6-8) — but the schedule actually lives in
   `bootstrap/app.php:25-27` (`->withSchedule(… ->daily())`), the Laravel 12
   location. So the README claim is TRUE; the trap is looking in the wrong file.
   If you're told this is "not scheduled", check `bootstrap/app.php` before
   believing it. (Verified 2026-07-05.)

## 5. Never ships without explicit human approval

- **New Composer or npm dependencies** (CLAUDE.md:88 "Do not change the
  application's dependencies without approval").
- **Schema changes to `bookings` or `event_types`** — the two load-bearing tables;
  even additive columns get sign-off (migration is still additive-only, §1).
- **Anything touching slug semantics** — uniqueness scope, immutability, URL shape
  (CLAUDE.md:21-22,46). This is the project's most fenced area.
- **Editing CLAUDE.md rules or Do-not list** (§4).
- **Deleting tests** (CLAUDE.md:181 "Do NOT delete tests without approval").
- **Guest-booking / availability scope creep** beyond the phased plan, teams, or
  multi-tenancy (CLAUDE.md:48-49) — out of v1 scope.

When in doubt: state the classification, list the gates you'll run, and ask.

## Provenance and maintenance

All anchors verified 2026-07-05 against `C:\xampp\htdocs\bookly` @ 086f30b. Re-verify:

- Rules/conventions: `Read C:\xampp\htdocs\bookly\CLAUDE.md` (lines 19-49)
- Status table + reminder claim: `Read C:\xampp\htdocs\bookly\README.md` (lines 17-29, 64)
- Checkpoint format: `Get-Content C:\xampp\htdocs\bookly\.claude\checkpoints.log`
- Commit style / history: `git -C C:\xampp\htdocs\bookly log --oneline` (10 commits, main only)
- Slug enforcement: `EventTypeController.php:37,71-88`; `UpdateEventTypeRequest.php:16-30` (no slug key); migration `…_create_event_types_table.php:25`
- Scheduler: `bootstrap/app.php:25-27`; PHP drift: `php -v` vs CLAUDE.md:7 and `composer.json:9`
- CI absence: `Test-Path C:\xampp\htdocs\bookly\.github` (False as of 2026-07-05)
