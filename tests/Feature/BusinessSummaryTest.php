<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountEntryStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Category;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 */
function summaryEntry(Company $company, Account $account, User $user, array $overrides = []): ChartAccountEntry
{
    return ChartAccountEntry::factory()->create([
        'company_id' => $company->id,
        'account_id' => $account->id,
        'currency' => $company->currency,
        'created_by' => $user->id,
        'status' => ChartAccountEntryStatus::Posted,
        ...$overrides,
    ]);
}

/**
 * @return array{user: User}
 */
function summaryBooks(): array
{
    $market = Category::factory()->create(['name' => 'Market']);
    $alpha = Company::factory()->create([
        'name' => 'Alpha',
        'currency' => 'PHP',
        'category_id' => $market->id,
    ]);
    $beta = Company::factory()->create([
        'name' => 'Beta',
        'currency' => 'USD',
    ]);
    $user = memberWithRole($alpha);
    $sales = Account::factory()->create([
        'company_id' => $alpha->id,
        'code' => '4000',
        'name' => 'Sales',
        'type' => AccountType::Income,
    ]);
    Account::factory()->create([
        'company_id' => $alpha->id,
        'code' => '5000',
        'name' => 'Operation Expenses',
        'type' => AccountType::Expense,
    ]);
    $purchases = Account::factory()->create([
        'company_id' => $alpha->id,
        'code' => '5100',
        'name' => 'Purchase Expenses',
        'type' => AccountType::Expense,
        'is_active' => false,
    ]);
    $cash = Account::factory()->create([
        'company_id' => $alpha->id,
        'code' => '1000',
        'name' => 'Cash',
        'type' => AccountType::Asset,
    ]);
    Account::factory()->create([
        'company_id' => $alpha->id,
        'code' => '5200',
        'name' => 'Idle Expense',
        'type' => AccountType::Expense,
        'is_active' => false,
    ]);
    $petty = Account::factory()->create([
        'company_id' => $alpha->id,
        'code' => '9000',
        'name' => 'Petty',
        'type' => AccountType::Expense,
    ]);
    $betaSales = Account::factory()->create([
        'company_id' => $beta->id,
        'code' => '4000',
        'name' => 'Sales',
        'type' => AccountType::Income,
    ]);

    summaryEntry($alpha, $sales, $user, [
        'date' => '2026-10-10',
        'amount_cents' => 10000,
        'cash_cents' => 6000,
        'credit_cents' => 4000,
    ]);
    summaryEntry($alpha, $sales, $user, [
        'date' => '2026-10-18',
        'amount_cents' => 2000,
        'cash_cents' => 2000,
        'credit_cents' => 0,
    ]);
    summaryEntry($alpha, $petty, $user, [
        'date' => '2026-10-12',
        'amount_cents' => 5000,
        'status' => ChartAccountEntryStatus::Draft,
    ]);
    summaryEntry($alpha, $sales, $user, [
        'date' => '2026-11-02',
        'amount_cents' => 100,
        'status' => ChartAccountEntryStatus::Draft,
    ]);
    summaryEntry($alpha, $purchases, $user, [
        'date' => '2026-10-20',
        'amount_cents' => 2500,
        'cash_cents' => 2500,
        'credit_cents' => 0,
    ]);
    summaryEntry($alpha, $cash, $user, [
        'date' => '2026-10-05',
        'amount_cents' => 3000,
        'cash_cents' => 3000,
        'credit_cents' => 0,
    ]);
    summaryEntry($alpha, $sales, $user, [
        'date' => '2026-11-01',
        'amount_cents' => 8000,
        'cash_cents' => 8000,
        'credit_cents' => 0,
    ]);
    summaryEntry($alpha, $sales, $user, [
        'date' => '2025-12-31',
        'amount_cents' => 100,
        'cash_cents' => 100,
        'credit_cents' => 0,
    ]);
    summaryEntry($beta, $betaSales, $user, [
        'date' => '2026-10-08',
        'amount_cents' => 5000,
        'cash_cents' => 5000,
        'credit_cents' => 0,
    ]);

    return ['user' => $user];
}

it('summarizes posted chart accounts for the selected month', function () {
    $this->travelTo('2026-10-23');
    $books = summaryBooks();

    $report = $this->actingAs($books['user'])->getJson('/api/v1/reports/business-summary?period=month&year=2026&month=10');

    $report->assertOk()
        ->assertJsonPath('meta.period', 'month')
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.month', 10)
        ->assertJsonPath('meta.from', '2026-10-01')
        ->assertJsonPath('meta.to', '2026-10-31')
        ->assertJsonPath('meta.years', [2025, 2026])
        ->assertJsonPath('meta.totals', null)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Alpha')
        ->assertJsonPath('data.0.currency', 'PHP')
        ->assertJsonPath('data.0.category_name', 'Market')
        ->assertJsonPath('data.0.income', '120.00')
        ->assertJsonPath('data.0.expense', '25.00')
        ->assertJsonPath('data.0.result', '95.00')
        ->assertJsonPath('data.0.drafts', 1)
        ->assertJsonCount(4, 'data.0.accounts')
        ->assertJsonPath('data.0.accounts.0.code', '1000')
        ->assertJsonPath('data.0.accounts.0.type', 'asset')
        ->assertJsonPath('data.0.accounts.0.amount', '30.00')
        ->assertJsonPath('data.0.accounts.1.code', '4000')
        ->assertJsonPath('data.0.accounts.1.amount', '120.00')
        ->assertJsonPath('data.0.accounts.1.cash', '80.00')
        ->assertJsonPath('data.0.accounts.1.credit', '40.00')
        ->assertJsonPath('data.0.accounts.1.entries', 2)
        ->assertJsonPath('data.0.accounts.2.code', '5000')
        ->assertJsonPath('data.0.accounts.2.amount', '0.00')
        ->assertJsonPath('data.0.accounts.2.entries', 0)
        ->assertJsonPath('data.0.accounts.3.code', '5100')
        ->assertJsonPath('data.0.accounts.3.amount', '25.00')
        ->assertJsonPath('data.1.name', 'Beta')
        ->assertJsonPath('data.1.currency', 'USD')
        ->assertJsonPath('data.1.category_name', null)
        ->assertJsonPath('data.1.income', '50.00')
        ->assertJsonPath('data.1.result', '50.00')
        ->assertJsonMissing(['code' => '5200'])
        ->assertJsonMissing(['code' => '9000']);
});

it('rolls the same totals up for the selected year', function () {
    $this->travelTo('2026-10-23');
    $books = summaryBooks();

    $report = $this->actingAs($books['user'])->getJson('/api/v1/reports/business-summary?period=year&year=2026');

    $report->assertOk()
        ->assertJsonPath('meta.period', 'year')
        ->assertJsonPath('meta.month', null)
        ->assertJsonPath('meta.from', '2026-01-01')
        ->assertJsonPath('meta.to', '2026-12-31')
        ->assertJsonPath('data.0.income', '200.00')
        ->assertJsonPath('data.0.expense', '25.00')
        ->assertJsonPath('data.0.result', '175.00')
        ->assertJsonPath('data.0.drafts', 2)
        ->assertJsonPath('data.0.accounts.1.amount', '200.00')
        ->assertJsonPath('data.0.accounts.1.entries', 3);
});

it('totals businesses that share a currency', function () {
    $north = Company::factory()->create(['name' => 'North', 'currency' => 'PHP']);
    $south = Company::factory()->create(['name' => 'South', 'currency' => 'PHP']);
    $user = memberWithRole($north);
    $sales = Account::factory()->create([
        'company_id' => $north->id,
        'code' => '4000',
        'name' => 'Sales',
        'type' => AccountType::Income,
    ]);
    $expense = Account::factory()->create([
        'company_id' => $south->id,
        'code' => '5000',
        'name' => 'Operation Expenses',
        'type' => AccountType::Expense,
    ]);
    summaryEntry($north, $sales, $user, ['date' => '2026-06-04', 'amount_cents' => 1000]);
    summaryEntry($south, $expense, $user, ['date' => '2026-06-09', 'amount_cents' => 400]);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/business-summary?period=month&year=2026&month=6');

    $report->assertOk()
        ->assertJsonPath('data.0.name', 'North')
        ->assertJsonPath('data.1.name', 'South')
        ->assertJsonPath('meta.totals.currency', 'PHP')
        ->assertJsonPath('meta.totals.income', '10.00')
        ->assertJsonPath('meta.totals.expense', '4.00')
        ->assertJsonPath('meta.totals.result', '6.00');
});

it('defaults to the current month', function () {
    $this->travelTo('2026-03-15');
    $company = Company::factory()->create(['name' => 'March Shop', 'currency' => 'PHP']);
    $user = memberWithRole($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/business-summary')
        ->assertOk()
        ->assertJsonPath('meta.period', 'month')
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.month', 3)
        ->assertJsonPath('meta.from', '2026-03-01')
        ->assertJsonPath('meta.to', '2026-03-31')
        ->assertJsonPath('data.0.name', 'March Shop')
        ->assertJsonPath('data.0.income', '0.00')
        ->assertJsonPath('data.0.accounts', [])
        ->assertJsonPath('meta.totals', null);
});

it('includes every business for a manager', function () {
    Company::factory()->create(['name' => 'First Shop']);
    $second = Company::factory()->create(['name' => 'Second Shop']);
    $manager = memberWithRole($second, CompanyRole::Accountant->value);

    $this->actingAs($manager)
        ->getJson('/api/v1/reports/business-summary?period=year&year=2026')
        ->assertOk()
        ->assertJsonFragment(['name' => 'First Shop'])
        ->assertJsonFragment(['name' => 'Second Shop']);
});

it('returns 403 when a staff member opens the dashboard', function () {
    $company = Company::factory()->create();
    Company::factory()->create(['name' => 'Hidden Books']);
    $staff = memberWithRole($company, CompanyRole::Staff->value);

    $this->actingAs($staff)
        ->getJson('/api/v1/reports/business-summary')
        ->assertForbidden()
        ->assertJsonPath('message', 'This dashboard is for admins and managers.')
        ->assertJsonMissing(['name' => 'Hidden Books']);
});

it('returns 401 when a guest opens the dashboard', function () {
    $this->getJson('/api/v1/reports/business-summary')->assertUnauthorized();
});

it('returns 422 when the summary period is invalid', function (string $query, string $field, string $message) {
    $company = Company::factory()->create();
    $user = memberWithRole($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/business-summary?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'period' => ['period=week', 'period', 'The selected period is invalid.'],
    'month' => ['period=month&month=13', 'month', 'The month field must be between 1 and 12.'],
    'year' => ['year=1800', 'year', 'The year field must be between 1900 and 9999.'],
]);
