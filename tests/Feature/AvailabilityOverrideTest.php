<?php

declare(strict_types=1);

use App\Models\AvailabilityOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── store ─────────────────────────────────────────────────────────────────────

it('guest cannot create an override', function () {
    $this->post(route('availability.overrides.store'), ['date' => now()->addDays(7)->toDateString()])
        ->assertRedirect(route('login'));
});

it('user can block an entire day', function () {
    $user = User::factory()->create();
    $date = now()->addDays(7)->toDateString();

    $this->actingAs($user)
        ->post(route('availability.overrides.store'), ['date' => $date])
        ->assertRedirect(route('availability.edit'));

    expect(AvailabilityOverride::where('user_id', $user->id)
        ->whereDate('date', $date)
        ->whereNull('start_time')
        ->exists())->toBeTrue();
});

it('user can add custom hours for a date', function () {
    $user = User::factory()->create();
    $date = now()->addDays(7)->toDateString();

    $this->actingAs($user)
        ->post(route('availability.overrides.store'), [
            'date' => $date,
            'start_time' => '13:00',
            'end_time' => '15:00',
        ])
        ->assertRedirect(route('availability.edit'));

    $override = AvailabilityOverride::where('user_id', $user->id)->first();

    expect($override->start_time)->toBe('13:00:00')
        ->and($override->end_time)->toBe('15:00:00');
});

it('rejects a past date', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('availability.overrides.store'), ['date' => now()->subDay()->toDateString()])
        ->assertSessionHasErrors('date');
});

it('rejects end time not after start time', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('availability.overrides.store'), [
            'date' => now()->addDays(7)->toDateString(),
            'start_time' => '15:00',
            'end_time' => '13:00',
        ])
        ->assertSessionHasErrors('end_time');
});

it('rejects a start time without an end time', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('availability.overrides.store'), [
            'date' => now()->addDays(7)->toDateString(),
            'start_time' => '13:00',
        ])
        ->assertSessionHasErrors('end_time');
});

it('rejects a full-day block when the date already has custom hours', function () {
    $user = User::factory()->create();
    $date = now()->addDays(7)->toDateString();

    AvailabilityOverride::factory()->withHours()->create(['user_id' => $user->id, 'date' => $date]);

    $this->actingAs($user)
        ->post(route('availability.overrides.store'), ['date' => $date])
        ->assertSessionHasErrors('date');
});

it('rejects custom hours when the date is already blocked', function () {
    $user = User::factory()->create();
    $date = now()->addDays(7)->toDateString();

    AvailabilityOverride::factory()->create(['user_id' => $user->id, 'date' => $date]);

    $this->actingAs($user)
        ->post(route('availability.overrides.store'), [
            'date' => $date,
            'start_time' => '13:00',
            'end_time' => '15:00',
        ])
        ->assertSessionHasErrors('date');
});

// ── destroy ───────────────────────────────────────────────────────────────────

it('user can delete their own override', function () {
    $user = User::factory()->create();
    $override = AvailabilityOverride::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('availability.overrides.destroy', $override))
        ->assertRedirect(route('availability.edit'));

    expect(AvailabilityOverride::find($override->id))->toBeNull();
});

it('user cannot delete another users override', function () {
    $user = User::factory()->create();
    $override = AvailabilityOverride::factory()->create(); // other user's

    $this->actingAs($user)
        ->delete(route('availability.overrides.destroy', $override))
        ->assertForbidden();

    expect(AvailabilityOverride::find($override->id))->not->toBeNull();
});

// ── availability page ─────────────────────────────────────────────────────────

it('availability page lists upcoming overrides only', function () {
    $user = User::factory()->create();
    AvailabilityOverride::factory()->create(['user_id' => $user->id, 'date' => now()->addDays(3)->toDateString()]);
    AvailabilityOverride::factory()->create(['user_id' => $user->id, 'date' => now()->subDays(3)->toDateString()]);
    AvailabilityOverride::factory()->create(); // other user's

    $this->actingAs($user)
        ->get(route('availability.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Availability/Edit')
            ->has('overrides', 1)
        );
});
