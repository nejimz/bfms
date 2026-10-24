<?php

namespace App\Policies;

use App\Models\AccountColumnReport;
use App\Models\User;
use App\Support\CurrentCompany;

class AccountColumnReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AccountColumnReport $accountColumnReport): bool
    {
        return $user->isAdmin() && $accountColumnReport->company_id === app(CurrentCompany::class)->id();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AccountColumnReport $accountColumnReport): bool
    {
        return $user->isAdmin() && $accountColumnReport->company_id === app(CurrentCompany::class)->id();
    }

    public function delete(User $user, AccountColumnReport $accountColumnReport): bool
    {
        return $this->update($user, $accountColumnReport);
    }
}
