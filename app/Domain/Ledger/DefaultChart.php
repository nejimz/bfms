<?php

namespace App\Domain\Ledger;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;

final class DefaultChart
{
    /**
     * A short generic chart. Tax accounts are intentionally absent.
     *
     * @var list<array{code: string, name: string, type: AccountType}>
     */
    public const ACCOUNTS = [
        ['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset],
        ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => AccountType::Asset],
        ['code' => '2000', 'name' => 'Accounts Payable', 'type' => AccountType::Liability],
        ['code' => '3000', 'name' => "Owner's Equity", 'type' => AccountType::Equity],
        ['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income],
        ['code' => '5000', 'name' => 'Rent Expense', 'type' => AccountType::Expense],
        ['code' => '5100', 'name' => 'Utilities Expense', 'type' => AccountType::Expense],
        ['code' => '5200', 'name' => 'Supplies Expense', 'type' => AccountType::Expense],
        ['code' => '5300', 'name' => 'Salaries Expense', 'type' => AccountType::Expense],
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
