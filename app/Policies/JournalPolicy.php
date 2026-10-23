<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Journal;
use App\Models\User;
use App\Support\CurrentCompany;

class JournalPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRead();
    }

    public function view(User $user, Journal $journal): bool
    {
        return $this->canRead() && $journal->company_id === app(CurrentCompany::class)->id();
    }

    public function create(User $user): bool
    {
        return $this->canWrite();
    }

    public function update(User $user, Journal $journal): bool
    {
        return $this->canWrite() && $journal->company_id === app(CurrentCompany::class)->id();
    }

    public function post(User $user, Journal $journal): bool
    {
        return $this->update($user, $journal);
    }

    private function canRead(): bool
    {
        return app(CurrentCompany::class)->role() !== null;
    }

    private function canWrite(): bool
    {
        return in_array(app(CurrentCompany::class)->role(), [
            CompanyRole::Owner->value,
            CompanyRole::Accountant->value,
        ], true);
    }
}
