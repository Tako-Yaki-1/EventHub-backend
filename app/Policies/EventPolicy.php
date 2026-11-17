<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function create(User $user): bool
    {
        return $user->role === Role::Admin;
    }

    public function update(User $user, Event $event): bool
    {
        return $user->role === Role::Admin;
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->role === Role::Admin;
    }

    public function viewAttendees(User $user, Event $event): bool
    {
        return $user->role === Role::Admin;
    }
}
