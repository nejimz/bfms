<?php

namespace App\Support;

use App\Models\Company;

class CurrentCompany
{
    private ?Company $company = null;

    private ?string $role = null;

    public function set(Company $company, string $role): void
    {
        $this->company = $company;
        $this->role = $role;
    }

    public function forget(): void
    {
        $this->company = null;
        $this->role = null;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function company(): Company
    {
        if ($this->company === null) {
            throw new \RuntimeException('No company is selected for this request.');
        }

        return $this->company;
    }

    public function role(): ?string
    {
        return $this->role;
    }
}
