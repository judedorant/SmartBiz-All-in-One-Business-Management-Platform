<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('appointments.manage') || $u->can('appointments.view_assigned');
    }

    public function view(User $u, Appointment $a): bool
    {
        return $u->can('appointments.manage') || ($u->can('appointments.view_assigned') && $a->assigned_to === $u->id);
    }

    public function create(User $u): bool
    {
        return $u->can('appointments.manage');
    }

    public function update(User $u, Appointment $a): bool
    {
        return $u->can('appointments.manage');
    }

    public function delete(User $u, Appointment $a): bool
    {
        return $u->can('appointments.manage');
    }
}
