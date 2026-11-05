<?php

namespace App\Policies;

use App\Models\InventoryAdjustment;
use App\Models\User;

class InventoryAdjustmentPolicy
{
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $user->isAdmin();
    }
}
