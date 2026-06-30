<?php

namespace App\Policies;

use App\Models\EventType;
use App\Models\User;

class EventTypePolicy
{
    public function update(User $user, EventType $eventType): bool
    {
        return $user->id === $eventType->user_id;
    }

    public function delete(User $user, EventType $eventType): bool
    {
        return $user->id === $eventType->user_id;
    }
}
