<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\ChartAccountEntry;
use App\Models\User;
use App\Support\CurrentCompany;

class ChartAccountEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRead();
    }

    public function view(User $user, ChartAccountEntry $entry): bool
    {
        return $this->canRead() && $entry->company_id === app(CurrentCompany::class)->id();
    }

    public function create(User $user): bool
    {
        return $this->canWrite();
    }

    public function update(User $user, ChartAccountEntry $entry): bool
    {
        return $this->canWrite() && $entry->company_id === app(CurrentCompany::class)->id();
    }

    public function delete(User $user, ChartAccountEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    public function post(User $user, ChartAccountEntry $entry): bool
    {
        return $this->update($user, $entry);
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
