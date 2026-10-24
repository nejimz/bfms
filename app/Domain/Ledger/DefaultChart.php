<?php

namespace App\Domain\Ledger;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;

final class DefaultChart
{
    /**
     * Accounts created with a new business.
     *
     * @var list<array{code: string, name: string, type: AccountType}>
     */
    public const ACCOUNTS = [
        ['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income],
        ['code' => '5000', 'name' => 'Operation Expenses', 'type' => AccountType::Expense],
        ['code' => '5100', 'name' => 'Purchase Expenses', 'type' => AccountType::Expense],
    ];

    public static function provision(Company $company): void
    {
        foreach (self::ACCOUNTS as $account) {
            Account::query()->create([
                'company_id' => $company->id,
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'is_active' => true,
            ]);
        }
    }
}
