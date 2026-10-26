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
        ->assertJsonPath('data.0.businesses.1.name', 'Gamma')
        ->assertJsonPath('data.0.businesses.1.sales', '0.00')
        ->assertJsonPath('data.0.businesses.1.operation_expense', '20.00')
        ->assertJsonPath('data.0.businesses.1.purchase_expense', '0.00')
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
        ->assertJsonPath('data.2.businesses.0.sales', '40.00');
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
