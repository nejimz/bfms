<?php

namespace Database\Seeders;

use App\Domain\Ledger\DefaultChart;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalPoster;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
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

        app(CurrentCompany::class)->set($company, CompanyRole::Owner->value);

        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $equity = Account::query()->where('code', '3000')->firstOrFail();

        $journal = app(JournalDraft::class)->create($owner, [
            'date' => now()->startOfMonth()->toDateString(),
            'reference' => 'OB-001',
            'memo' => 'Opening balance',
            'lines' => [
                [
                    'account_id' => $cash->id,
                    'description' => 'Cash on hand',
                    'debit' => '10000.00',
                    'credit' => '0.00',
                ],
                [
                    'account_id' => $equity->id,
                    'description' => "Owner's capital",
                    'debit' => '0.00',
                    'credit' => '10000.00',
                ],
            ],
        ]);

        app(JournalPoster::class)->post($journal, $owner);

        app(CurrentCompany::class)->forget();
    }
}
