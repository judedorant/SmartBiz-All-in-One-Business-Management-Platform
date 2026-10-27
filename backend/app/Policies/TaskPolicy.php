<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tasks.assign') || $user->can('tasks.manage_assigned');
    }

    public function view(User $user, Task $task): bool
    {
        return ! $task->trashed() && ($user->can('tasks.assign') || ($user->can('tasks.manage_assigned') && $task->assigned_to === $user->id));
    }

    public function create(User $user): bool
    {
        return $user->can('tasks.assign');
    }

    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->can('tasks.assign');
    }
}
