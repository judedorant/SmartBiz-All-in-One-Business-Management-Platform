<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function sendPasswordReset(User $user, User $subject): bool
    {
        return $user->status === 'active' && $user->hasRole('Admin') && $user->can('users.manage')
            && $user->getConnection()->getName() === $subject->getConnection()->getName()
            && $user->getConnection()->getDatabaseName() === $subject->getConnection()->getDatabaseName();
    }

    public function view(User $user, User $subject): bool
    {
        return $user->id === $subject->id || $user->can('users.manage');
    }

    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }
}
