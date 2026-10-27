<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('leads.manage') || $u->can('leads.update_assigned');
    }

    public function view(User $u, Lead $lead): bool
    {
        return $u->can('leads.manage') || ($u->can('leads.update_assigned') && $lead->assigned_to === $u->id);
    }

    public function create(User $u): bool
    {
        return $u->can('leads.manage');
    }

    public function update(User $u, Lead $lead): bool
    {
        return $this->view($u, $lead);
    }

    public function delete(User $u, Lead $lead): bool
    {
        return $u->can('leads.manage');
    }

    public function convert(User $u, Lead $lead): bool
    {
        return $u->can('leads.manage') && $u->can('customers.manage');
    }
}
