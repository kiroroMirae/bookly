<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAvailabilityRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AvailabilityController extends Controller
{
    public function edit(): Response
    {
        $user = auth()->user();

        $windows = $user->availabilityWindows()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $overrides = $user->availabilityOverrides()
            ->whereDate('date', '>=', now($user->timezone)->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return Inertia::render('Availability/Edit', [
            'windows' => $windows,
            'overrides' => $overrides,
        ]);
    }

    public function update(UpdateAvailabilityRequest $request): RedirectResponse
    {
        $user = $request->user();
        $windows = $request->validated()['windows'];

        DB::transaction(function () use ($user, $windows) {
            $user->availabilityWindows()->delete();

            foreach ($windows as $window) {
                $user->availabilityWindows()->create($window);
            }
        });

        return redirect()->route('availability.edit');
    }
}
