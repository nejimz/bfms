<?php

namespace App\Policies;

use App\Models\ChartAccountEntry;
use App\Models\User;
use App\Support\CurrentCompany;

class ChartAccountEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canUse();
    }

    public function view(User $user, ChartAccountEntry $entry): bool
    {
        return $this->canUse() && $entry->company_id === app(CurrentCompany::class)->id();
    }

    public function create(User $user): bool
    {
        return $this->canUse();
    }

    public function update(User $user, ChartAccountEntry $entry): bool
    {
        return $this->canUse() && $entry->company_id === app(CurrentCompany::class)->id();
    }

    public function delete(User $user, ChartAccountEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    public function post(User $user, ChartAccountEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    private function canUse(): bool
    {
        return app(CurrentCompany::class)->id() !== null;
    }
}
