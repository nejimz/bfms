<?php

namespace App\Policies;

use App\Models\Inventory;
use App\Models\User;

class InventoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Inventory $inventory): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Inventory $inventory): bool
    {
        return $user->isAdmin();
    }
}
