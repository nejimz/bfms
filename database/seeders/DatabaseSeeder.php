<?php

namespace Database\Seeders;

use App\Domain\Ledger\DefaultChart;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['name' => 'Harbor Goods'],
            [
                'description' => 'Retail goods and supplies.',
                'currency' => 'PHP',
            ],
        );

        if (User::query()->where('email', 'owner@bfms.test')->doesntExist()) {
            User::factory()->admin()->create([
                'name' => 'Demo Admin',
                'email' => 'owner@bfms.test',
                'password' => 'password',
            ]);
        }

        if ($company->wasRecentlyCreated) {
            DefaultChart::provision($company);
        }

        $this->call(ChartAccountEntrySeeder::class);
    }
}
