<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $user, User $subject): bool
    {
        return $user->id === $subject->id || $user->can('users.manage');
    }

    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }
}
