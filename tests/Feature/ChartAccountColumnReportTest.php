<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountEntryStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\User;

function columnMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    $user = User::factory()->create();
    $company->users()->attach($user->id, ['role' => $role]);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function columnEntry(Company $company, Account $account, User $user, array $overrides = []): ChartAccountEntry
{
    return ChartAccountEntry::factory()->create([
        'company_id' => $company->id,
        'account_id' => $account->id,
        'currency' => $company->currency,
        'created_by' => $user->id,
        ...$overrides,
    ]);
}

it('lists one row per entry for accounts used in the month', function () {
    $this->travelTo('2026-10-23');

    $company = Company::factory()->create(['currency' => 'PHP']);
    $staff = columnMember($company, CompanyRole::Staff->value);
    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'type' => AccountType::Asset,
    ]);
    $rent = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '5000',
        'name' => 'Rent',
        'type' => AccountType::Expense,
        'is_active' => false,
    ]);
    Account::factory()->create([
        'company_id' => $company->id,
        'code' => '4000',
        'name' => 'Sales',
        'type' => AccountType::Income,
    ]);
    $float = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '9000',
        'name' => 'Float',
        'type' => AccountType::Asset,
    ]);
    columnEntry($company, $cash, $staff, [
        'date' => '2026-10-05',
        'particulars' => 'Drawer count',
        'amount_cents' => 2500,
        'status' => ChartAccountEntryStatus::Draft,
    ]);
    columnEntry($company, $rent, $staff, [
        'date' => '2026-10-20',
        'particulars' => 'October rent',
        'amount_cents' => 8000,
        'status' => ChartAccountEntryStatus::Posted,
    ]);
    columnEntry($company, $float, $staff, [
        'date' => '2026-11-01',
        'particulars' => 'November float',
        'amount_cents' => 1000,
    ]);
    columnEntry($company, $cash, $staff, [
        'date' => '2025-12-31',
        'particulars' => 'Prior year',
        'amount_cents' => 100,
    ]);

    $other = Company::factory()->create();
    $otherUser = columnMember($other);
    $secret = Account::factory()->create([
        'company_id' => $other->id,
        'code' => '1000',
        'name' => 'Secret Cash',
        'type' => AccountType::Asset,
    ]);
    columnEntry($other, $secret, $otherUser, [
        'date' => '2026-10-05',
        'particulars' => 'Secret deposit',
        'amount_cents' => 99900,
    ]);

    $report = $this->actingAs($staff)->getJson('/api/v1/reports/chart-account-columns?year=2026&month=10');

    $report->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonCount(2, 'meta.columns')
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.month', 10)
        ->assertJsonPath('meta.sort', 'asc')
        ->assertJsonPath('meta.currency', 'PHP')
        ->assertJsonPath('meta.years', [2025, 2026])
        ->assertJsonPath('meta.columns.0', [
            'id' => (string) $cash->id,
            'code' => '1000',
            'name' => 'Cash',
        ])
        ->assertJsonPath('meta.columns.1', [
            'id' => (string) $rent->id,
            'code' => '5000',
            'name' => 'Rent',
        ])
        ->assertJsonFragment([
            'date' => '2026-10-05',
            'particulars' => 'Drawer count',
            'status' => 'draft',
            'account_id' => (string) $cash->id,
            'amount' => '25.00',
        ])
        ->assertJsonFragment([
            'date' => '2026-10-20',
            'particulars' => 'October rent',
            'status' => 'posted',
            'account_id' => (string) $rent->id,
            'amount' => '80.00',
        ])
        ->assertJsonMissing(['particulars' => 'November float'])
        ->assertJsonMissing(['particulars' => 'Secret deposit'])
        ->assertJsonMissing(['particulars' => 'Prior year'])
        ->assertJsonMissing(['code' => '4000'])
        ->assertJsonMissing(['code' => '9000']);
});

it('sorts the month by date ascending and breaks ties with the id', function () {
    $company = Company::factory()->create();
    $user = columnMember($company);
    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '1000',
        'type' => AccountType::Asset,
    ]);
    columnEntry($company, $cash, $user, ['date' => '2026-10-20', 'particulars' => 'Later']);
    $first = columnEntry($company, $cash, $user, ['date' => '2026-10-10', 'particulars' => 'Same day first']);
    $second = columnEntry($company, $cash, $user, ['date' => '2026-10-10', 'particulars' => 'Same day second']);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/chart-account-columns?year=2026&month=10');

    $report->assertOk()
        ->assertJsonPath('meta.sort', 'asc')
        ->assertJsonPath('data.0.id', (string) $first->id)
        ->assertJsonPath('data.0.particulars', 'Same day first')
        ->assertJsonPath('data.1.id', (string) $second->id)
        ->assertJsonPath('data.1.particulars', 'Same day second')
        ->assertJsonPath('data.2.particulars', 'Later');
});

it('sorts the month by date descending and breaks ties with the id', function () {
    $company = Company::factory()->create();
    $user = columnMember($company);
    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '1000',
        'type' => AccountType::Asset,
    ]);
    columnEntry($company, $cash, $user, ['date' => '2026-10-20', 'particulars' => 'Later']);
    $first = columnEntry($company, $cash, $user, ['date' => '2026-10-10', 'particulars' => 'Same day first']);
    $second = columnEntry($company, $cash, $user, ['date' => '2026-10-10', 'particulars' => 'Same day second']);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/chart-account-columns?year=2026&month=10&sort=desc');

    $report->assertOk()
        ->assertJsonPath('meta.sort', 'desc')
        ->assertJsonPath('data.0.particulars', 'Later')
        ->assertJsonPath('data.1.id', (string) $second->id)
        ->assertJsonPath('data.1.particulars', 'Same day second')
        ->assertJsonPath('data.2.id', (string) $first->id)
        ->assertJsonPath('data.2.particulars', 'Same day first');
});

it('defaults to the current year and month', function () {
    $this->travelTo('2026-10-23');

    $company = Company::factory()->create(['currency' => 'PHP']);
    $user = columnMember($company);
    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '1000',
        'type' => AccountType::Asset,
    ]);
    columnEntry($company, $cash, $user, [
        'date' => '2026-10-08',
        'particulars' => 'October cash',
        'amount_cents' => 1500,
    ]);
    columnEntry($company, $cash, $user, [
        'date' => '2026-09-30',
        'particulars' => 'September cash',
        'amount_cents' => 1500,
    ]);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/chart-account-columns');

    $report->assertOk()
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.month', 10)
        ->assertJsonPath('meta.sort', 'asc')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.particulars', 'October cash')
        ->assertJsonMissing(['particulars' => 'September cash']);
});

it('rejects a month outside 1 to 12', function () {
    $company = Company::factory()->create();
    $user = columnMember($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/chart-account-columns?year=2026&month=13')
        ->assertUnprocessable()
        ->assertJsonPath('errors.month.0', 'The month field must be between 1 and 12.');
});

it('refuses a user who belongs to no business', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/reports/chart-account-columns')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not belong to a company.');
});

it('refuses a guest', function () {
    $this->getJson('/api/v1/reports/chart-account-columns')->assertUnauthorized();
});
