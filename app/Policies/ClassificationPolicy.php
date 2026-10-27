<?php

namespace App\Policies;

use App\Models\Classification;
use App\Models\User;

class ClassificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Classification $classification): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Classification $classification): bool
    {
        return $user->isAdmin();
    }
}
