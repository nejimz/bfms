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
function yearlySummaryEntry(Company $company, Account $account, User $user, array $overrides = []): ChartAccountEntry
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

function yearlySummaryAccount(Company $company, string $code, string $name, AccountType $type): Account
{
    return Account::factory()->create([
        'company_id' => $company->id,
        'code' => $code,
        'name' => $name,
        'type' => $type,
    ]);
}

it('lists every category and the posted yearly sales and expenses of its businesses', function () {
    $this->travelTo('2026-10-26');
    $market = Category::factory()->create(['name' => 'market']);
    $stalls = Category::factory()->create(['name' => 'Stalls']);
    $alpha = Company::factory()->create([
        'name' => 'Alpha',
        'currency' => 'PHP',
        'category_id' => $market->id,
    ]);
    $gamma = Company::factory()->create([
        'name' => 'Gamma',
        'currency' => 'PHP',
        'category_id' => $market->id,
    ]);
    $quiet = Company::factory()->create([
        'name' => 'Quiet',
        'currency' => 'PHP',
        'category_id' => $market->id,
    ]);
    $beta = Company::factory()->create([
        'name' => 'Beta',
        'currency' => 'USD',
    ]);
    $user = memberWithRole($alpha);
    $alphaSales = yearlySummaryAccount($alpha, '4000', 'Sales', AccountType::Income);
    $alphaOperation = yearlySummaryAccount($alpha, '5000', 'Operation Expenses', AccountType::Expense);
    $alphaPurchases = yearlySummaryAccount($alpha, '5100', 'Purchase Expenses', AccountType::Expense);
    $petty = yearlySummaryAccount($alpha, '9000', 'Petty', AccountType::Expense);
    $gammaOperation = yearlySummaryAccount($gamma, '5000', 'Operation Expenses', AccountType::Expense);
    $betaSales = yearlySummaryAccount($beta, '4000', 'Sales', AccountType::Income);

    yearlySummaryEntry($alpha, $alphaSales, $user, ['date' => '2025-12-31', 'amount_cents' => 100]);
    yearlySummaryEntry($alpha, $alphaSales, $user, ['date' => '2026-01-01', 'amount_cents' => 5000]);
    yearlySummaryEntry($alpha, $alphaOperation, $user, ['date' => '2026-12-31', 'amount_cents' => 2500]);
    yearlySummaryEntry($alpha, $alphaPurchases, $user, ['date' => '2026-06-15', 'amount_cents' => 1500]);
    yearlySummaryEntry($alpha, $alphaPurchases, $user, ['date' => '2027-01-01', 'amount_cents' => 7000]);
    yearlySummaryEntry($alpha, $alphaSales, $user, [
        'date' => '2026-06-15',
        'amount_cents' => 99999,
        'status' => ChartAccountEntryStatus::Draft,
    ]);
    yearlySummaryEntry($alpha, $petty, $user, ['date' => '2026-06-15', 'amount_cents' => 8000]);
    yearlySummaryEntry($gamma, $gammaOperation, $user, ['date' => '2026-03-02', 'amount_cents' => 2000]);
    yearlySummaryEntry($beta, $betaSales, $user, ['date' => '2026-08-08', 'amount_cents' => 4000]);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/yearly-summary?year=2026');

    $report->assertOk()
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.years', [2025, 2026, 2027])
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', (string) $market->id)
        ->assertJsonPath('data.0.name', 'market')
        ->assertJsonCount(3, 'data.0.businesses')
        ->assertJsonPath('data.0.businesses.0.name', 'Alpha')
        ->assertJsonPath('data.0.businesses.0.currency', 'PHP')
        ->assertJsonPath('data.0.businesses.0.sales', '50.00')
        ->assertJsonPath('data.0.businesses.0.operation_expense', '25.00')
        ->assertJsonPath('data.0.businesses.0.purchase_expense', '15.00')
        ->assertJsonCount(12, 'data.0.businesses.0.months')
        ->assertJsonPath('data.0.businesses.0.months.0.month', 1)
        ->assertJsonPath('data.0.businesses.0.months.0.sales', '50.00')
        ->assertJsonPath('data.0.businesses.0.months.0.operation_expense', '0.00')
        ->assertJsonPath('data.0.businesses.0.months.0.purchase_expense', '0.00')
        ->assertJsonPath('data.0.businesses.0.months.1.month', 2)
        ->assertJsonPath('data.0.businesses.0.months.1.sales', '0.00')
        ->assertJsonPath('data.0.businesses.0.months.5.month', 6)
        ->assertJsonPath('data.0.businesses.0.months.5.sales', '0.00')
        ->assertJsonPath('data.0.businesses.0.months.5.purchase_expense', '15.00')
        ->assertJsonPath('data.0.businesses.0.months.11.month', 12)
        ->assertJsonPath('data.0.businesses.0.months.11.operation_expense', '25.00')
        ->assertJsonPath('data.0.businesses.0.months.11.sales', '0.00')
        ->assertJsonPath('data.0.businesses.1.name', 'Gamma')
        ->assertJsonPath('data.0.businesses.1.sales', '0.00')
        ->assertJsonPath('data.0.businesses.1.operation_expense', '20.00')
        ->assertJsonPath('data.0.businesses.1.purchase_expense', '0.00')
        ->assertJsonPath('data.0.businesses.1.months.2.month', 3)
        ->assertJsonPath('data.0.businesses.1.months.2.operation_expense', '20.00')
        ->assertJsonPath('data.0.businesses.2.name', 'Quiet')
        ->assertJsonPath('data.0.businesses.2.sales', '0.00')
        ->assertJsonPath('data.0.businesses.2.operation_expense', '0.00')
        ->assertJsonPath('data.0.businesses.2.purchase_expense', '0.00')
        ->assertJsonPath('data.1.id', (string) $stalls->id)
        ->assertJsonPath('data.1.name', 'Stalls')
        ->assertJsonPath('data.1.businesses', [])
        ->assertJsonPath('data.2.id', null)
        ->assertJsonPath('data.2.name', 'No category')
        ->assertJsonPath('data.2.businesses.0.name', 'Beta')
        ->assertJsonPath('data.2.businesses.0.currency', 'USD')
        ->assertJsonPath('data.2.businesses.0.sales', '40.00')
        ->assertJsonPath('data.2.businesses.0.months.7.month', 8)
        ->assertJsonPath('data.2.businesses.0.months.7.sales', '40.00');
});

it('leaves out the uncategorized group when every business has a category', function () {
    $market = Category::factory()->create(['name' => 'Market']);
    $company = Company::factory()->create([
        'name' => 'Alpha',
        'category_id' => $market->id,
    ]);
    $user = memberWithRole($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/yearly-summary?year=2026')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Market')
        ->assertJsonPath('data.0.businesses.0.name', 'Alpha')
        ->assertJsonMissing(['name' => 'No category']);
});

it('uses the current year when the year is omitted', function () {
    $this->travelTo('2026-10-26');
    $company = Company::factory()->create(['name' => 'Alpha']);
    $user = memberWithRole($company);
    $sales = yearlySummaryAccount($company, '4000', 'Sales', AccountType::Income);

    yearlySummaryEntry($company, $sales, $user, ['date' => '2025-05-01', 'amount_cents' => 100]);
    yearlySummaryEntry($company, $sales, $user, ['date' => '2026-05-01', 'amount_cents' => 5000]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/yearly-summary')
        ->assertOk()
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('data.0.businesses.0.sales', '50.00');
});

it('includes every business for a manager', function () {
    Category::factory()->create(['name' => 'Empty Lane']);
    Company::factory()->create(['name' => 'First Shop']);
    $second = Company::factory()->create(['name' => 'Second Shop']);
    $manager = memberWithRole($second, CompanyRole::Accountant->value);

    $this->actingAs($manager)
        ->getJson('/api/v1/reports/yearly-summary?year=2026')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Empty Lane'])
        ->assertJsonFragment(['name' => 'First Shop'])
        ->assertJsonFragment(['name' => 'Second Shop']);
});

it('returns 403 when a staff member opens the yearly summary', function () {
    $company = Company::factory()->create();
    Company::factory()->create(['name' => 'Hidden Books']);
    $staff = memberWithRole($company, CompanyRole::Staff->value);

    $this->actingAs($staff)
        ->getJson('/api/v1/reports/yearly-summary')
        ->assertForbidden()
        ->assertJsonPath('message', 'This summary is for admins and managers.')
        ->assertJsonMissing(['name' => 'Hidden Books']);
});

it('returns 401 when a guest opens the yearly summary', function () {
    $this->getJson('/api/v1/reports/yearly-summary')->assertUnauthorized();
});

it('lists every day of posted sales and expenses for one business month', function () {
    $alpha = Company::factory()->create(['name' => 'Alpha', 'currency' => 'PHP']);
    $beta = Company::factory()->create(['name' => 'Beta', 'currency' => 'USD']);
    $user = memberWithRole($alpha);
    $sales = yearlySummaryAccount($alpha, '4000', 'Sales', AccountType::Income);
    $operation = yearlySummaryAccount($alpha, '5000', 'Operation Expenses', AccountType::Expense);
    $purchases = yearlySummaryAccount($alpha, '5100', 'Purchase Expenses', AccountType::Expense);
    $petty = yearlySummaryAccount($alpha, '9000', 'Petty', AccountType::Expense);
    $betaSales = yearlySummaryAccount($beta, '4000', 'Sales', AccountType::Income);

    yearlySummaryEntry($alpha, $sales, $user, ['date' => '2026-05-31', 'amount_cents' => 100]);
    yearlySummaryEntry($alpha, $sales, $user, ['date' => '2026-06-01', 'amount_cents' => 5000]);
    yearlySummaryEntry($alpha, $purchases, $user, ['date' => '2026-06-15', 'amount_cents' => 1500]);
    yearlySummaryEntry($alpha, $sales, $user, [
        'date' => '2026-06-15',
        'amount_cents' => 99999,
        'status' => ChartAccountEntryStatus::Draft,
    ]);
    yearlySummaryEntry($alpha, $petty, $user, ['date' => '2026-06-15', 'amount_cents' => 8000]);
    yearlySummaryEntry($alpha, $operation, $user, ['date' => '2026-06-30', 'amount_cents' => 2500]);
    yearlySummaryEntry($alpha, $sales, $user, ['date' => '2026-07-01', 'amount_cents' => 7000]);
    yearlySummaryEntry($beta, $betaSales, $user, ['date' => '2026-06-10', 'amount_cents' => 4000]);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/yearly-summary/days?business='.$alpha->id.'&year=2026&month=6');

    $report->assertOk()
        ->assertJsonPath('data.id', (string) $alpha->id)
        ->assertJsonPath('data.name', 'Alpha')
        ->assertJsonPath('data.currency', 'PHP')
        ->assertJsonPath('data.year', 2026)
        ->assertJsonPath('data.month', 6)
        ->assertJsonCount(30, 'data.days')
        ->assertJsonPath('data.sales', '50.00')
        ->assertJsonPath('data.operation_expense', '25.00')
        ->assertJsonPath('data.purchase_expense', '15.00')
        ->assertJsonPath('data.days.0.day', 1)
        ->assertJsonPath('data.days.0.sales', '50.00')
        ->assertJsonPath('data.days.0.operation_expense', '0.00')
        ->assertJsonPath('data.days.0.purchase_expense', '0.00')
        ->assertJsonPath('data.days.1.day', 2)
        ->assertJsonPath('data.days.1.sales', '0.00')
        ->assertJsonPath('data.days.14.day', 15)
        ->assertJsonPath('data.days.14.sales', '0.00')
        ->assertJsonPath('data.days.14.purchase_expense', '15.00')
        ->assertJsonPath('data.days.29.day', 30)
        ->assertJsonPath('data.days.29.operation_expense', '25.00')
        ->assertJsonPath('data.days.29.sales', '0.00')
        ->assertJsonMissing(['name' => 'Beta']);
});

it('lists the leap day when february has 29 days', function () {
    $company = Company::factory()->create(['name' => 'Alpha']);
    $user = memberWithRole($company);
    $sales = yearlySummaryAccount($company, '4000', 'Sales', AccountType::Income);

    yearlySummaryEntry($company, $sales, $user, ['date' => '2024-02-29', 'amount_cents' => 5000]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/yearly-summary/days?business='.$company->id.'&year=2024&month=2')
        ->assertOk()
        ->assertJsonCount(29, 'data.days')
        ->assertJsonPath('data.days.28.day', 29)
        ->assertJsonPath('data.days.28.sales', '50.00');
});

it('lists 28 days when february is not a leap year', function () {
    $company = Company::factory()->create(['name' => 'Alpha']);
    $user = memberWithRole($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/yearly-summary/days?business='.$company->id.'&year=2025&month=2')
        ->assertOk()
        ->assertJsonCount(28, 'data.days')
        ->assertJsonPath('data.days.27.day', 28);
});

it('shows another business month to a manager', function () {
    $first = Company::factory()->create(['name' => 'First Shop']);
    $second = Company::factory()->create(['name' => 'Second Shop']);
    $manager = memberWithRole($second, CompanyRole::Accountant->value);
    $sales = yearlySummaryAccount($first, '4000', 'Sales', AccountType::Income);

    yearlySummaryEntry($first, $sales, $manager, ['date' => '2026-06-04', 'amount_cents' => 5000]);

    $this->actingAs($manager)
        ->getJson('/api/v1/reports/yearly-summary/days?business='.$first->id.'&year=2026&month=6')
        ->assertOk()
        ->assertJsonPath('data.name', 'First Shop')
        ->assertJsonPath('data.days.3.sales', '50.00');
});

it('returns 403 when a staff member opens a business month', function () {
    $company = Company::factory()->create();
    $hidden = Company::factory()->create(['name' => 'Hidden Books']);
    $staff = memberWithRole($company, CompanyRole::Staff->value);

    $this->actingAs($staff)
        ->getJson('/api/v1/reports/yearly-summary/days?business='.$hidden->id.'&year=2026&month=6')
        ->assertForbidden()
        ->assertJsonPath('message', 'This summary is for admins and managers.')
        ->assertJsonMissing(['name' => 'Hidden Books']);
});

it('returns 401 when a guest opens a business month', function () {
    $this->getJson('/api/v1/reports/yearly-summary/days?business=1&year=2026&month=6')->assertUnauthorized();
});

it('returns 404 when the business month does not exist', function () {
    $company = Company::factory()->create(['name' => 'Hidden Books']);
    $user = memberWithRole($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/yearly-summary/days?business=999999&year=2026&month=6')
        ->assertNotFound()
        ->assertJsonMissing(['name' => 'Hidden Books']);
});

it('returns 422 when the business month is invalid', function (string $query, string $field, string $message) {
    $company = Company::factory()->create(['name' => 'Hidden Books']);
    $user = memberWithRole($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/yearly-summary/days?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message])
        ->assertJsonMissing(['name' => 'Hidden Books']);
})->with([
    'business' => ['year=2026&month=6', 'business', 'The business field is required.'],
    'year' => ['business=1&month=6&year=1800', 'year', 'The year field must be between 1900 and 9999.'],
    'month' => ['business=1&year=2026&month=13', 'month', 'The month field must be between 1 and 12.'],
]);

it('returns 422 when the summary year is invalid', function (string $query, string $message) {
    $company = Company::factory()->create(['name' => 'Hidden Books']);
    $user = memberWithRole($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/yearly-summary?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['year' => $message])
        ->assertJsonMissing(['name' => 'Hidden Books']);
})->with([
    'range' => ['year=1800', 'The year field must be between 1900 and 9999.'],
    'integer' => ['year=week', 'The year field must be an integer.'],
]);
