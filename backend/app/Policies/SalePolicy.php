<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.manage') || ($user->can('dashboard.view') && $user->can('leads.update_assigned'));
    }

    public function view(User $user, Sale $sale): bool
    {
        return Sale::visibleTo($user)->whereKey($sale->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('sales.manage');
    }

    public function void(User $user, Sale $sale): bool
    {
        return $user->can('sales.manage');
    }
}
