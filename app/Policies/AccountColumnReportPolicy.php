<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\AccountColumnReport;
use App\Models\User;
use App\Support\CurrentCompany;

class AccountColumnReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRead();
    }

    public function view(User $user, AccountColumnReport $accountColumnReport): bool
    {
        return $this->canRead() && $accountColumnReport->company_id === app(CurrentCompany::class)->id();
    }

    public function create(User $user): bool
    {
        return $this->canWrite();
    }

    public function update(User $user, AccountColumnReport $accountColumnReport): bool
    {
        return $this->canWrite() && $accountColumnReport->company_id === app(CurrentCompany::class)->id();
    }

    public function delete(User $user, AccountColumnReport $accountColumnReport): bool
    {
        return $this->update($user, $accountColumnReport);
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
