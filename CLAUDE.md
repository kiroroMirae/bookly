# Bookly

Calendly-style appointment scheduling SaaS. Users define event types (with duration, color, and booking slug), share their public booking page, and manage incoming appointments. Hosts control their availability; guests book slots without an account.

## Stack

- Laravel 12 (PHP 8.3), MySQL, Inertia.js v2, Vue 3 (Composition API, `<script setup>`)
- Tailwind CSS v3, Vite
- Pest v3 for tests, Pint for formatting
- Auth: Laravel Breeze (email + password); users have `username` and `timezone` fields
- Dev environment: Windows + XAMPP (MySQL on 3306), `php artisan serve` + `npm run dev`

## Key domain concepts

- **EventType** — belongs to a User; has `name`, `slug` (unique per user, auto-generated), `duration_minutes`, `color`, `description`, `is_active`
- **Booking** — (Phase 3+) a guest reservation against an EventType; has `starts_at`, `ends_at`, `guest_name`, `guest_email`, `status`
- **Availability** — (Phase 3+) per-user weekly schedule defining when they can be booked

## Architecture rules (non-negotiable)

1. **Slug uniqueness is per-user**, not global. Two users can share the same slug; the booking URL is `/{username}/{slug}`.
2. **Slugs are immutable after creation** — never regenerate on update. Only the user-facing name changes.
3. **Authorization via Policies** — all ownership checks go through `EventTypePolicy` (auto-discovered). Use `Gate::authorize()` in controllers.
4. **Form Requests for all validation** at the HTTP boundary. Never validate inline in controllers.
5. **Inertia pages live in `resources/js/Pages/`** — PascalCase subdirectory per resource (`EventTypes/Index.vue`, `EventTypes/Create.vue`, `EventTypes/Edit.vue`).

## Conventions

- Form Requests for validation, Policies for authorization — no exceptions.
- `$request->validated()` only — never `$request->all()`.
- Pest feature tests REQUIRED for every controller action; write tests before implementation.
- Run after any PHP change: `vendor/bin/pint --dirty` then `php artisan test --compact`.
- After adding new Vue pages: `npm run build` (Vite manifest must include new files for tests to pass).
- Eloquent: avoid N+1; eager-load relations when listing.
- Migrations: never edit a migration that has been committed; add a new one.

## Commands

- Serve: `php artisan serve` + `npm run dev`
- Tests: `php artisan test --compact` / `php artisan test --compact --filter=EventType`
- Format: `vendor/bin/pint --dirty`
- Fresh DB: `php artisan migrate:fresh --seed`

## Do not

- Do not modify slugs on update — they are set once at creation.
- Do not store secrets in code; keys live in `.env`.
- Do not add guest-booking or availability logic until Phase 3 is scoped.
- Do not introduce teams or multi-tenant models — v1 is single-user-per-account.

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v2
- laravel/framework (LARAVEL) - v12
- tightenco/ziggy (ZIGGY) - v2
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v3
- phpunit/phpunit (PHPUNIT) - v11
- @inertiajs/vue3 (INERTIA_VUE) - v2
- tailwindcss (TAILWINDCSS) - v3
- vue (VUE) - v3

## Skills Activation

This project has domain-specific skills available in `.claude/skills/`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods.
- Check for existing components to reuse before writing a new one.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`.
- Use explicit return type declarations and type hints for all method parameters.
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Components live in `resources/js/Pages`. Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS activate `inertia-vue-development` skill when working with Inertia Vue client-side patterns.

# Inertia v2

- Use all Inertia features from v1 and v2.
- New features: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (migrations, controllers, models, etc.).
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models.
- Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, run `npm run build`.

=== laravel/v12 rules ===

# Laravel 12

- Since Laravel 11, Laravel has a new streamlined file structure.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty` before finalizing changes.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` skill when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
