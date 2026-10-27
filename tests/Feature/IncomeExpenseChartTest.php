<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountEntryStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\Classification;
use App\Models\Company;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 */
function chartEntry(Company $company, Account $account, User $user, array $overrides = []): ChartAccountEntry
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

function chartAccount(Company $company, string $code, string $name, AccountType $type): Account
{
    return Account::factory()->create([
        'company_id' => $company->id,
        'code' => $code,
        'name' => $name,
        'type' => $type,
    ]);
}

it('charts posted sales and the two expense accounts for the last twelve months', function () {
    $this->travelTo('2026-10-24');
    $market = Classification::factory()->create(['name' => 'Market']);
    $alpha = Company::factory()->create([
        'name' => 'Alpha',
        'currency' => 'PHP',
        'classification_id' => $market->id,
    ]);
    $beta = Company::factory()->create([
        'name' => 'Beta',
        'currency' => 'USD',
    ]);
    $quiet = Company::factory()->create([
        'name' => 'Quiet',
        'currency' => 'PHP',
    ]);
    $user = memberWithRole($alpha);
    $sales = chartAccount($alpha, '4000', 'Sales', AccountType::Income);
    $operation = chartAccount($alpha, '5000', 'Operation Expenses', AccountType::Expense);
    $purchases = chartAccount($alpha, '5100', 'Purchase Expenses', AccountType::Expense);
    $petty = chartAccount($alpha, '9000', 'Petty', AccountType::Expense);
    $betaSales = chartAccount($beta, '4000', 'Sales', AccountType::Income);

    chartEntry($alpha, $sales, $user, ['date' => '2025-10-31', 'amount_cents' => 100]);
    chartEntry($alpha, $sales, $user, ['date' => '2025-11-01', 'amount_cents' => 5000]);
    chartEntry($alpha, $sales, $user, ['date' => '2026-10-24', 'amount_cents' => 10000]);
    chartEntry($alpha, $sales, $user, ['date' => '2026-10-31', 'amount_cents' => 2000]);
    chartEntry($alpha, $sales, $user, [
        'date' => '2026-10-10',
        'amount_cents' => 99999,
        'status' => ChartAccountEntryStatus::Draft,
    ]);
    chartEntry($alpha, $operation, $user, ['date' => '2026-10-02', 'amount_cents' => 2500]);
    chartEntry($alpha, $purchases, $user, ['date' => '2026-09-15', 'amount_cents' => 1500]);
    chartEntry($alpha, $petty, $user, ['date' => '2026-10-12', 'amount_cents' => 8000]);
    chartEntry($alpha, $sales, $user, ['date' => '2026-11-01', 'amount_cents' => 7000]);
    chartEntry($beta, $betaSales, $user, ['date' => '2026-10-08', 'amount_cents' => 4000]);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/income-expense');

    $report->assertOk()
        ->assertJsonPath('meta.group', 'business')
        ->assertJsonPath('meta.from', '2025-11-01')
        ->assertJsonPath('meta.to', '2026-10-31')
        ->assertJsonCount(12, 'meta.months')
        ->assertJsonPath('meta.months.0.key', '2025-11')
        ->assertJsonPath('meta.months.0.year', 2025)
        ->assertJsonPath('meta.months.0.month', 11)
        ->assertJsonPath('meta.months.11.key', '2026-10')
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'Alpha')
        ->assertJsonPath('data.0.currency', 'PHP')
        ->assertJsonPath('data.0.classification_name', 'Market')
        ->assertJsonPath('data.0.business_count', 1)
        ->assertJsonCount(12, 'data.0.months')
        ->assertJsonPath('data.0.months.0.key', '2025-11')
        ->assertJsonPath('data.0.months.0.sales', '50.00')
        ->assertJsonPath('data.0.months.0.operation_expense', '0.00')
        ->assertJsonPath('data.0.months.0.purchase_expense', '0.00')
        ->assertJsonPath('data.0.months.10.key', '2026-09')
        ->assertJsonPath('data.0.months.10.purchase_expense', '15.00')
        ->assertJsonPath('data.0.months.11.sales', '120.00')
        ->assertJsonPath('data.0.months.11.operation_expense', '25.00')
        ->assertJsonPath('data.0.months.11.purchase_expense', '0.00')
        ->assertJsonPath('data.1.name', 'Beta')
        ->assertJsonPath('data.1.currency', 'USD')
        ->assertJsonPath('data.1.classification_name', null)
        ->assertJsonPath('data.1.months.11.sales', '40.00')
        ->assertJsonPath('data.2.name', 'Quiet')
        ->assertJsonPath('data.2.months.0.sales', '0.00')
        ->assertJsonPath('data.2.months.11.sales', '0.00')
        ->assertJsonMissing(['sales' => '80.00'])
        ->assertJsonMissing(['sales' => '1.00'])
        ->assertJsonMissing(['sales' => '70.00']);
});

it('sums a classification without mixing currencies', function () {
    $this->travelTo('2026-10-24');
    $market = Classification::factory()->create(['name' => 'Market']);
    $stalls = Classification::factory()->create(['name' => 'Stalls']);
    $alpha = Company::factory()->create([
        'name' => 'Alpha',
        'currency' => 'PHP',
        'classification_id' => $market->id,
    ]);
    $gamma = Company::factory()->create([
        'name' => 'Gamma',
        'currency' => 'PHP',
        'classification_id' => $market->id,
    ]);
    $delta = Company::factory()->create([
        'name' => 'Delta',
        'currency' => 'USD',
        'classification_id' => $market->id,
    ]);
    $east = Company::factory()->create([
        'name' => 'East',
        'currency' => 'PHP',
        'classification_id' => $stalls->id,
    ]);
    $lone = Company::factory()->create([
        'name' => 'Lone',
        'currency' => 'PHP',
    ]);
    $user = memberWithRole($alpha);
    $alphaSales = chartAccount($alpha, '4000', 'Sales', AccountType::Income);
    $gammaOperation = chartAccount($gamma, '5000', 'Operation Expenses', AccountType::Expense);
    $deltaSales = chartAccount($delta, '4000', 'Sales', AccountType::Income);
    $eastPurchases = chartAccount($east, '5100', 'Purchase Expenses', AccountType::Expense);
    $loneSales = chartAccount($lone, '4000', 'Sales', AccountType::Income);

    chartEntry($alpha, $alphaSales, $user, ['date' => '2026-10-04', 'amount_cents' => 10000]);
    chartEntry($gamma, $gammaOperation, $user, ['date' => '2026-10-06', 'amount_cents' => 2000]);
    chartEntry($delta, $deltaSales, $user, ['date' => '2026-10-08', 'amount_cents' => 5000]);
    chartEntry($east, $eastPurchases, $user, ['date' => '2026-10-09', 'amount_cents' => 3000]);
    chartEntry($lone, $loneSales, $user, ['date' => '2026-10-11', 'amount_cents' => 7000]);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/income-expense?group=classification');

    $report->assertOk()
        ->assertJsonPath('meta.group', 'classification')
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.id', 'classification:'.$market->id.':PHP')
        ->assertJsonPath('data.0.name', 'Market')
        ->assertJsonPath('data.0.currency', 'PHP')
        ->assertJsonPath('data.0.business_count', 2)
        ->assertJsonPath('data.0.months.11.sales', '100.00')
        ->assertJsonPath('data.0.months.11.operation_expense', '20.00')
        ->assertJsonPath('data.0.months.11.purchase_expense', '0.00')
        ->assertJsonPath('data.1.id', 'classification:'.$market->id.':USD')
        ->assertJsonPath('data.1.name', 'Market')
        ->assertJsonPath('data.1.currency', 'USD')
        ->assertJsonPath('data.1.business_count', 1)
        ->assertJsonPath('data.1.months.11.sales', '50.00')
        ->assertJsonPath('data.2.name', 'Stalls')
        ->assertJsonPath('data.2.currency', 'PHP')
        ->assertJsonPath('data.2.months.11.purchase_expense', '30.00')
        ->assertJsonPath('data.3.name', 'No classification')
        ->assertJsonPath('data.3.currency', 'PHP')
        ->assertJsonPath('data.3.classification_name', null)
        ->assertJsonPath('data.3.months.11.sales', '70.00');
});

it('opens the chart on the twelve months ending in january', function () {
    $this->travelTo('2026-01-15');
    $company = Company::factory()->create(['name' => 'January Shop']);
    $user = memberWithRole($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/income-expense')
        ->assertOk()
        ->assertJsonPath('meta.from', '2025-02-01')
        ->assertJsonPath('meta.to', '2026-01-31')
        ->assertJsonPath('meta.months.0.key', '2025-02')
        ->assertJsonPath('meta.months.11.key', '2026-01')
        ->assertJsonPath('data.0.name', 'January Shop')
        ->assertJsonPath('data.0.months.0.sales', '0.00');
});

it('includes every business for a manager', function () {
    Company::factory()->create(['name' => 'First Shop']);
    $second = Company::factory()->create(['name' => 'Second Shop']);
    $manager = memberWithRole($second, CompanyRole::Accountant->value);

    $this->actingAs($manager)
        ->getJson('/api/v1/reports/income-expense')
        ->assertOk()
        ->assertJsonFragment(['name' => 'First Shop'])
        ->assertJsonFragment(['name' => 'Second Shop']);
});

it('returns 403 when a staff member opens the income chart', function () {
    $company = Company::factory()->create();
    Company::factory()->create(['name' => 'Hidden Books']);
    $staff = memberWithRole($company, CompanyRole::Staff->value);

    $this->actingAs($staff)
        ->getJson('/api/v1/reports/income-expense')
        ->assertForbidden()
        ->assertJsonPath('message', 'This chart is for admins and managers.')
        ->assertJsonMissing(['name' => 'Hidden Books']);
});

it('returns 401 when a guest opens the income chart', function () {
    $this->getJson('/api/v1/reports/income-expense')->assertUnauthorized();
});

it('returns 422 when the chart group is invalid', function () {
    $company = Company::factory()->create();
    $user = memberWithRole($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/income-expense?group=week')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['group' => 'The selected group is invalid.']);
});
