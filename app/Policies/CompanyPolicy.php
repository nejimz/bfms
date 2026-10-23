<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\User;
use App\Support\CurrentCompany;

class CompanyPolicy
{
    public function create(User $user): bool
    {
        return app(CurrentCompany::class)->role() === CompanyRole::Owner->value;
    }
}
