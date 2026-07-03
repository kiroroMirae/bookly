<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAvailabilityOverrideRequest;
use App\Models\AvailabilityOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class AvailabilityOverrideController extends Controller
{
    public function store(StoreAvailabilityOverrideRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Store H:i:s so string comparisons behave the same on every driver
        if (isset($data['start_time'])) {
            $data['start_time'] .= ':00';
            $data['end_time'] .= ':00';
        }

        $request->user()->availabilityOverrides()->create($data);

        return redirect()->route('availability.edit');
    }

    public function destroy(AvailabilityOverride $override): RedirectResponse
    {
        Gate::authorize('delete', $override);

        $override->delete();

        return redirect()->route('availability.edit');
    }
}
