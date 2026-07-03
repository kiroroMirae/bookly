<?php

namespace App\Policies;

use App\Models\AvailabilityOverride;
use App\Models\User;

class AvailabilityOverridePolicy
{
    public function delete(User $user, AvailabilityOverride $availabilityOverride): bool
    {
        return $user->id === $availabilityOverride->user_id;
    }
}
