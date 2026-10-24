<?php

namespace App\Policies;

use App\Models\Journal;
use App\Models\User;
use App\Support\CurrentCompany;

class JournalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Journal $journal): bool
    {
        return $user->isAdmin() && $journal->company_id === app(CurrentCompany::class)->id();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Journal $journal): bool
    {
        return $user->isAdmin() && $journal->company_id === app(CurrentCompany::class)->id();
    }

    public function post(User $user, Journal $journal): bool
    {
        return $this->update($user, $journal);
    }
}
