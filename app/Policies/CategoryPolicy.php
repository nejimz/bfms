<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Category;
use App\Models\User;
use App\Support\CurrentCompany;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return app(CurrentCompany::class)->role() !== null;
    }

    public function create(User $user): bool
    {
        return app(CurrentCompany::class)->role() === CompanyRole::Owner->value;
    }

    public function update(User $user, Category $category): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->create($user);
    }
}
