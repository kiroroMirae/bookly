<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Http\Requests\StoreEventTypeRequest;
use App\Http\Requests\UpdateEventTypeRequest;
use App\Models\EventType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EventTypeController extends Controller
{
    public function index(): Response
    {
        $eventTypes = auth()->user()->eventTypes()->latest()->get();

        return Inertia::render('EventTypes/Index', [
            'eventTypes' => $eventTypes,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('EventTypes/Create');
    }

    public function store(StoreEventTypeRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        EventType::create([
            ...$data,
            'slug' => $this->uniqueSlug($data['name'], $user->id),
            'user_id' => $user->id,
        ]);

        return redirect()->route('event-types.index');
    }

    public function edit(EventType $eventType): Response
    {
        Gate::authorize('update', $eventType);

        return Inertia::render('EventTypes/Edit', [
            'eventType' => $eventType,
        ]);
    }

    public function update(UpdateEventTypeRequest $request, EventType $eventType): RedirectResponse
    {
        Gate::authorize('update', $eventType);

        $eventType->update($request->validated());

        return redirect()->route('event-types.index');
    }

    public function destroy(EventType $eventType): RedirectResponse
    {
        Gate::authorize('delete', $eventType);

        DB::transaction(function () use ($eventType) {
            $hasActiveBooking = $eventType->bookings()
                ->lockForUpdate()
                ->where('status', BookingStatus::Confirmed)
                ->where('starts_at', '>=', now())
                ->exists();

            abort_unless(! $hasActiveBooking, 422, 'This event type has upcoming confirmed bookings and cannot be deleted.');

            $eventType->delete();
        });

        return redirect()->route('event-types.index');
    }

    private function uniqueSlug(string $name, int $userId, ?int $excludeId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (
            EventType::where('user_id', $userId)
                ->where('slug', $slug)
                ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
