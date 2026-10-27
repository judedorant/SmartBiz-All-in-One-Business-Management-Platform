<?php

namespace App\Policies;

use App\Models\AutomationWorkflow;
use App\Models\User;

class AutomationWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('automations.manage');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, AutomationWorkflow $workflow): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, AutomationWorkflow $workflow): bool
    {
        return $this->viewAny($user);
    }
}
