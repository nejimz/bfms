<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Support\CurrentCompany;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRead();
    }

    public function view(User $user, Account $account): bool
    {
        return $this->canRead() && $account->company_id === app(CurrentCompany::class)->id();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Account $account): bool
    {
        return $user->isAdmin() && $account->company_id === app(CurrentCompany::class)->id();
    }

    private function canRead(): bool
    {
        return app(CurrentCompany::class)->id() !== null;
    }
}
