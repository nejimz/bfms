<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return app(CurrentCompany::class)->role() !== null;
    }

    public function create(User $user): bool
    {
        return app(CurrentCompany::class)->role() === CompanyRole::Owner->value;
    }

    public function update(User $user, Company $company): bool
    {
        $membership = $user->companies()->where('companies.id', $company->id)->first();

        return $membership?->pivot->role === CompanyRole::Owner->value;
    }
}
