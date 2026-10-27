<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('customers.manage') || $u->can('customers.view_assigned');
    }

    public function view(User $u, Customer $c): bool
    {
        return Customer::visibleTo($u)->whereKey($c->id)->exists();
    }

    public function create(User $u): bool
    {
        return $u->can('customers.manage');
    }

    public function update(User $u, Customer $c): bool
    {
        return $u->can('customers.manage');
    }

    public function delete(User $u, Customer $c): bool
    {
        return $u->can('customers.manage');
    }
}
