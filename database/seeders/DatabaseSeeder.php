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
        $company = Company::query()->create([
            'name' => 'Harbor Goods',
            'description' => 'Retail goods and supplies.',
            'currency' => 'PHP',
        ]);

        User::factory()->admin()->create([
            'name' => 'Demo Admin',
            'email' => 'owner@bfms.test',
            'password' => 'password',
        ]);

        DefaultChart::provision($company);

        $this->call(ChartAccountEntrySeeder::class);
    }
}
