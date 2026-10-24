<?php

namespace Database\Seeders;

use App\Domain\Ledger\DefaultChart;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->create([
            'name' => 'Harbor Goods',
            'description' => 'Retail goods and supplies.',
            'currency' => 'PHP',
        ]);

        $owner = User::factory()->create([
            'name' => 'Demo Owner',
            'email' => 'owner@bfms.test',
            'password' => 'password',
        ]);

        $company->users()->attach($owner->id, ['role' => CompanyRole::Owner->value]);

        DefaultChart::provision($company);
    }
}
