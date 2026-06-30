<?php

declare(strict_types=1);

use App\Models\AvailabilityWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── edit ──────────────────────────────────────────────────────────────────────

it('guest is redirected from availability page', function () {
    $this->get(route('availability.edit'))->assertRedirect(route('login'));
});

it('user can view their availability windows', function () {
    $user = User::factory()->create();
    AvailabilityWindow::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('availability.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Availability/Edit')
            ->has('windows', 3)
        );
});

it('other users windows are not returned', function () {
    $user = User::factory()->create();
    AvailabilityWindow::factory()->count(2)->create(); // another user's

    $this->actingAs($user)
        ->get(route('availability.edit'))
        ->assertInertia(fn ($page) => $page->has('windows', 0));
});

// ── update ────────────────────────────────────────────────────────────────────

it('guest cannot update availability', function () {
    $this->put(route('availability.update'), ['windows' => []])
        ->assertRedirect(route('login'));
});

it('user can replace their availability windows', function () {
    $user = User::factory()->create();
    AvailabilityWindow::factory()->create(['user_id' => $user->id, 'day_of_week' => 0]);

    $this->actingAs($user)
        ->put(route('availability.update'), [
            'windows' => [
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
                ['day_of_week' => 3, 'start_time' => '10:00', 'end_time' => '16:00'],
            ],
        ])
        ->assertRedirect(route('availability.edit'));

    expect(AvailabilityWindow::where('user_id', $user->id)->count())->toBe(2)
        ->and(AvailabilityWindow::where('user_id', $user->id)->where('day_of_week', 0)->exists())->toBeFalse();
});

it('user can clear all availability windows', function () {
    $user = User::factory()->create();
    AvailabilityWindow::factory()->count(2)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->put(route('availability.update'), ['windows' => []])
        ->assertRedirect(route('availability.edit'));

    expect(AvailabilityWindow::where('user_id', $user->id)->count())->toBe(0);
});

it('update does not affect other users windows', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    AvailabilityWindow::factory()->create(['user_id' => $otherUser->id, 'day_of_week' => 1]);

    $this->actingAs($user)
        ->put(route('availability.update'), ['windows' => []])
        ->assertRedirect(route('availability.edit'));

    expect(AvailabilityWindow::where('user_id', $otherUser->id)->count())->toBe(1);
});

it('update validates day_of_week must be between 0 and 6', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('availability.update'), [
            'windows' => [
                ['day_of_week' => 7, 'start_time' => '09:00', 'end_time' => '17:00'],
            ],
        ])
        ->assertSessionHasErrors('windows.0.day_of_week');
});

it('update validates end_time must be after start_time', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('availability.update'), [
            'windows' => [
                ['day_of_week' => 1, 'start_time' => '17:00', 'end_time' => '09:00'],
            ],
        ])
        ->assertSessionHasErrors('windows.0.end_time');
});

it('update validates required window fields', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('availability.update'), [
            'windows' => [
                ['day_of_week' => 1],
            ],
        ])
        ->assertSessionHasErrors(['windows.0.start_time', 'windows.0.end_time']);
});

it('update rejects overlapping windows on the same day', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('availability.update'), [
            'windows' => [
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
                ['day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '17:00'],
            ],
        ])
        ->assertSessionHasErrors('windows.1.start_time');
});
