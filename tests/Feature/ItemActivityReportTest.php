<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountEntryStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\ChartAccountEntryLine;
use App\Models\Company;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 */
function activityEntry(Company $company, Account $account, User $user, array $overrides = []): ChartAccountEntry
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

function activityAccount(Company $company, string $code, string $name, AccountType $type): Account
{
    return Account::factory()->create([
        'company_id' => $company->id,
        'code' => $code,
        'name' => $name,
        'type' => $type,
    ]);
}

function activityLine(ChartAccountEntry $entry, string $name, string $quantity, int $amountCents, int $position = 0): void
{
    ChartAccountEntryLine::factory()->create([
        'company_id' => $entry->company_id,
        'chart_account_entry_id' => $entry->id,
        'position' => $position,
        'name' => $name,
        'quantity' => $quantity,
        'amount_cents' => $amountCents,
    ]);
}

it('sums posted sales quantities by day', function () {
    $company = Company::factory()->create(['name' => 'Alpha', 'currency' => 'PHP']);
    $other = Company::factory()->create(['name' => 'Beta', 'currency' => 'PHP']);
    $user = memberWithRole($company);
    $sales = activityAccount($company, '4000', 'Sales', AccountType::Income);
    $purchases = activityAccount($company, '5100', 'Purchase Expenses', AccountType::Expense);
    $otherSales = activityAccount($other, '4000', 'Sales', AccountType::Income);

    $first = activityEntry($company, $sales, $user, ['date' => '2026-10-08']);
    activityLine($first, ' Rice ', '1.5', 150);
    activityLine($first, '   ', '1', 50, 1);
    $second = activityEntry($company, $sales, $user, ['date' => '2026-10-09']);
    activityLine($second, 'rice', '2.25', 225);
    $soap = activityEntry($company, $sales, $user, ['date' => '2026-10-08']);
    activityLine($soap, 'Soap', '1', 100);
    activityLine($soap, 'Soap', '2', 200, 1);
    $pin = activityEntry($company, $sales, $user, ['date' => '2026-10-08']);
    activityLine($pin, 'Pin', '2', 1);
    $draft = activityEntry($company, $sales, $user, [
        'date' => '2026-10-08',
        'status' => ChartAccountEntryStatus::Draft,
    ]);
    activityLine($draft, 'Draft eggs', '9', 9000);
    $purchase = activityEntry($company, $purchases, $user, ['date' => '2026-10-08']);
    activityLine($purchase, 'Secret flour', '4', 400);
    $hidden = activityEntry($other, $otherSales, $user, ['date' => '2026-10-08']);
    activityLine($hidden, 'Hidden rice', '8', 800);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/item-activity?book=sales&grain=day&year=2026&month=10');

    $report->assertOk()
        ->assertJsonPath('meta.book', 'sales')
        ->assertJsonPath('meta.grain', 'day')
        ->assertJsonPath('meta.account_code', '4000')
        ->assertJsonPath('meta.account_name', 'Sales')
        ->assertJsonPath('meta.currency', 'PHP')
        ->assertJsonPath('meta.item_label', 'Item sold')
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.month', 10)
        ->assertJsonPath('meta.from', '2026-10-01')
        ->assertJsonPath('meta.to', '2026-10-31')
        ->assertJsonCount(2, 'meta.periods')
        ->assertJsonPath('meta.periods.0.key', '2026-10-08')
        ->assertJsonPath('meta.periods.0.label', '8 Oct')
        ->assertJsonPath('meta.periods.1.label', '9 Oct')
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'Pin')
        ->assertJsonPath('data.0.periods.0.quantity', '2')
        ->assertJsonPath('data.0.periods.0.amount', '0.01')
        ->assertJsonPath('data.0.periods.1.quantity', '0')
        ->assertJsonPath('data.0.total.quantity', '2')
        ->assertJsonPath('data.0.total.amount', '0.01')
        ->assertJsonPath('data.0.total.entries', 1)
        ->assertJsonPath('data.0.total.average', '0.01')
        ->assertJsonPath('data.1.name', 'Rice')
        ->assertJsonPath('data.1.periods.0.quantity', '1.5')
        ->assertJsonPath('data.1.periods.0.amount', '1.50')
        ->assertJsonPath('data.1.periods.1.quantity', '2.25')
        ->assertJsonPath('data.1.periods.1.amount', '2.25')
        ->assertJsonPath('data.1.total.quantity', '3.75')
        ->assertJsonPath('data.1.total.amount', '3.75')
        ->assertJsonPath('data.1.total.entries', 2)
        ->assertJsonPath('data.1.total.average', '1.00')
        ->assertJsonPath('data.2.name', 'Soap')
        ->assertJsonPath('data.2.periods.0.quantity', '3')
        ->assertJsonPath('data.2.periods.0.amount', '3.00')
        ->assertJsonPath('data.2.total.entries', 1)
        ->assertJsonPath('data.2.total.average', '1.00')
        ->assertJsonPath('meta.totals.periods.0.quantity', '6.5')
        ->assertJsonPath('meta.totals.periods.0.amount', '4.51')
        ->assertJsonPath('meta.totals.periods.1.quantity', '2.25')
        ->assertJsonPath('meta.totals.periods.1.amount', '2.25')
        ->assertJsonPath('meta.totals.total.quantity', '8.75')
        ->assertJsonPath('meta.totals.total.amount', '6.76')
        ->assertJsonPath('meta.totals.total.entries', 4)
        ->assertJsonPath('meta.totals.total.average', '0.77')
        ->assertJsonMissing(['name' => 'Draft eggs'])
        ->assertJsonMissing(['name' => 'Secret flour'])
        ->assertJsonMissing(['name' => 'Hidden rice']);
});

it('buckets a month into monday weeks and keeps only days inside the month', function () {
    $company = Company::factory()->create();
    $user = memberWithRole($company);
    $sales = activityAccount($company, '4000', 'Sales', AccountType::Income);

    $before = activityEntry($company, $sales, $user, ['date' => '2026-09-30']);
    activityLine($before, 'September rice', '9', 900);
    $opening = activityEntry($company, $sales, $user, ['date' => '2026-10-01']);
    activityLine($opening, 'Rice', '2', 200);
    $middle = activityEntry($company, $sales, $user, ['date' => '2026-10-08']);
    activityLine($middle, 'Rice', '3', 300);
    $after = activityEntry($company, $sales, $user, ['date' => '2026-11-01']);
    activityLine($after, 'November rice', '7', 700);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/item-activity?book=sales&grain=week&year=2026&month=10');

    $report->assertOk()
        ->assertJsonPath('meta.grain', 'week')
        ->assertJsonPath('meta.month', 10)
        ->assertJsonCount(5, 'meta.periods')
        ->assertJsonPath('meta.periods.0.key', '2026-09-28')
        ->assertJsonPath('meta.periods.0.label', '1–4 Oct')
        ->assertJsonPath('meta.periods.1.key', '2026-10-05')
        ->assertJsonPath('meta.periods.1.label', '5–11 Oct')
        ->assertJsonPath('meta.periods.4.label', '26–31 Oct')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Rice')
        ->assertJsonPath('data.0.periods.0.quantity', '2')
        ->assertJsonPath('data.0.periods.0.amount', '2.00')
        ->assertJsonPath('data.0.periods.1.quantity', '3')
        ->assertJsonPath('data.0.periods.1.amount', '3.00')
        ->assertJsonPath('data.0.periods.2.quantity', '0')
        ->assertJsonPath('data.0.periods.2.amount', '0.00')
        ->assertJsonPath('data.0.total.quantity', '5')
        ->assertJsonPath('data.0.total.amount', '5.00')
        ->assertJsonPath('data.0.total.entries', 2)
        ->assertJsonPath('data.0.total.average', '1.00')
        ->assertJsonMissing(['name' => 'September rice'])
        ->assertJsonMissing(['name' => 'November rice']);
});

it('labels a week that touches the month on one day with that day', function () {
    $company = Company::factory()->create();
    $user = memberWithRole($company);
    $sales = activityAccount($company, '4000', 'Sales', AccountType::Income);
    $opening = activityEntry($company, $sales, $user, ['date' => '2026-02-01']);
    activityLine($opening, 'Eggs', '4', 400);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/item-activity?book=sales&grain=week&year=2026&month=2');

    $report->assertOk()
        ->assertJsonCount(5, 'meta.periods')
        ->assertJsonPath('meta.periods.0.key', '2026-01-26')
        ->assertJsonPath('meta.periods.0.label', '1 Feb')
        ->assertJsonPath('meta.periods.1.key', '2026-02-02')
        ->assertJsonPath('meta.periods.1.label', '2–8 Feb')
        ->assertJsonPath('meta.periods.4.label', '23–28 Feb')
        ->assertJsonPath('data.0.periods.0.quantity', '4')
        ->assertJsonPath('data.0.periods.0.amount', '4.00')
        ->assertJsonPath('data.0.periods.1.quantity', '0');
});

it('sums a year by month and leaves other years out', function () {
    $company = Company::factory()->create();
    $user = memberWithRole($company);
    $sales = activityAccount($company, '4000', 'Sales', AccountType::Income);
    $january = activityEntry($company, $sales, $user, ['date' => '2026-01-15']);
    activityLine($january, 'Rice', '2', 200);
    $october = activityEntry($company, $sales, $user, ['date' => '2026-10-08']);
    activityLine($october, 'Rice', '5', 500);
    $previous = activityEntry($company, $sales, $user, ['date' => '2025-12-01']);
    activityLine($previous, 'Old rice', '9', 900);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/item-activity?book=sales&grain=month&year=2026');

    $report->assertOk()
        ->assertJsonPath('meta.grain', 'month')
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.month', null)
        ->assertJsonPath('meta.from', '2026-01-01')
        ->assertJsonPath('meta.to', '2026-12-31')
        ->assertJsonCount(12, 'meta.periods')
        ->assertJsonPath('meta.periods.0.key', '2026-01')
        ->assertJsonPath('meta.periods.0.label', 'Jan')
        ->assertJsonPath('meta.periods.9.label', 'Oct')
        ->assertJsonPath('data.0.name', 'Rice')
        ->assertJsonPath('data.0.periods.0.quantity', '2')
        ->assertJsonPath('data.0.periods.0.amount', '2.00')
        ->assertJsonPath('data.0.periods.1.quantity', '0')
        ->assertJsonPath('data.0.periods.9.quantity', '5')
        ->assertJsonPath('data.0.periods.9.amount', '5.00')
        ->assertJsonPath('data.0.total.quantity', '7')
        ->assertJsonPath('data.0.total.amount', '7.00')
        ->assertJsonPath('data.0.total.entries', 2)
        ->assertJsonMissing(['name' => 'Old rice']);
});

it('returns one column per posted year and the current year', function () {
    $this->travelTo('2026-10-24');
    $company = Company::factory()->create();
    $user = memberWithRole($company);
    $sales = activityAccount($company, '4000', 'Sales', AccountType::Income);
    $previous = activityEntry($company, $sales, $user, ['date' => '2025-06-01']);
    activityLine($previous, 'Rice', '4', 400);
    $current = activityEntry($company, $sales, $user, ['date' => '2026-10-08']);
    activityLine($current, 'Rice', '6', 600);
    $draft = activityEntry($company, $sales, $user, [
        'date' => '2024-05-01',
        'status' => ChartAccountEntryStatus::Draft,
    ]);
    activityLine($draft, 'Draft rice', '3', 300);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/item-activity?book=sales&grain=year');

    $report->assertOk()
        ->assertJsonPath('meta.grain', 'year')
        ->assertJsonPath('meta.year', null)
        ->assertJsonPath('meta.month', null)
        ->assertJsonPath('meta.from', '2025-01-01')
        ->assertJsonPath('meta.to', '2026-12-31')
        ->assertJsonPath('meta.years', [2025, 2026])
        ->assertJsonCount(2, 'meta.periods')
        ->assertJsonPath('meta.periods.0.key', '2025')
        ->assertJsonPath('meta.periods.0.label', '2025')
        ->assertJsonPath('meta.periods.1.key', '2026')
        ->assertJsonPath('data.0.periods.0.quantity', '4')
        ->assertJsonPath('data.0.periods.0.amount', '4.00')
        ->assertJsonPath('data.0.periods.1.quantity', '6')
        ->assertJsonPath('data.0.periods.1.amount', '6.00')
        ->assertJsonPath('data.0.total.quantity', '10')
        ->assertJsonPath('data.0.total.amount', '10.00')
        ->assertJsonPath('data.0.total.entries', 2)
        ->assertJsonMissing(['name' => 'Draft rice']);
});

it('returns twelve empty months when nothing was posted', function () {
    $this->travelTo('2026-10-24');
    $company = Company::factory()->create();
    $user = memberWithRole($company);
    activityAccount($company, '4000', 'Sales', AccountType::Income);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/item-activity?book=sales');

    $report->assertOk()
        ->assertJsonPath('meta.grain', 'month')
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.month', null)
        ->assertJsonCount(12, 'meta.periods')
        ->assertJsonPath('meta.periods.0.key', '2026-01')
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.totals.periods.0.quantity', '0')
        ->assertJsonPath('meta.totals.periods.0.amount', '0.00')
        ->assertJsonPath('meta.totals.total.quantity', '0')
        ->assertJsonPath('meta.totals.total.amount', '0.00')
        ->assertJsonPath('meta.totals.total.entries', 0)
        ->assertJsonPath('meta.totals.total.average', null);
});

it('limits the report to the selected book', function (string $book, string $code, string $label, string $item) {
    $company = Company::factory()->create();
    $user = memberWithRole($company);
    $sales = activityAccount($company, '4000', 'Sales', AccountType::Income);
    $operation = activityAccount($company, '5000', 'Operation Expenses', AccountType::Expense);
    $purchases = activityAccount($company, '5100', 'Purchase Expenses', AccountType::Expense);
    $sold = activityEntry($company, $sales, $user, ['date' => '2026-04-04']);
    activityLine($sold, 'Pandesal', '2', 200);
    $spent = activityEntry($company, $operation, $user, ['date' => '2026-04-04']);
    activityLine($spent, 'Rent', '1', 800);
    $bought = activityEntry($company, $purchases, $user, ['date' => '2026-04-04']);
    activityLine($bought, 'Flour', '3', 300);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/item-activity?book='.$book.'&grain=month&year=2026')
        ->assertOk()
        ->assertJsonPath('meta.account_code', $code)
        ->assertJsonPath('meta.account_name', match ($code) {
            '4000' => 'Sales',
            '5000' => 'Operation Expenses',
            default => 'Purchase Expenses',
        })
        ->assertJsonPath('meta.item_label', $label)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', $item);
})->with([
    'sales' => ['sales', '4000', 'Item sold', 'Pandesal'],
    'operation' => ['operation-expense', '5000', 'Item', 'Rent'],
    'purchase' => ['purchase-expense', '5100', 'Item purchased', 'Flour'],
]);

it('opens the item report for a staff member on their own business', function () {
    $other = Company::factory()->create(['name' => 'Other Shop']);
    $company = Company::factory()->create(['name' => 'Staff Shop']);
    $staff = memberWithRole($company, CompanyRole::Staff->value);
    $sales = activityAccount($company, '4000', 'Sales', AccountType::Income);
    $otherSales = activityAccount($other, '4000', 'Sales', AccountType::Income);
    $own = activityEntry($company, $sales, $staff, ['date' => '2026-10-08']);
    activityLine($own, 'Own rice', '1', 100);
    $foreign = activityEntry($other, $otherSales, $staff, ['date' => '2026-10-08']);
    activityLine($foreign, 'Foreign rice', '5', 500);

    $this->actingAs($staff)
        ->getJson('/api/v1/reports/item-activity?book=sales&grain=day&year=2026&month=10')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Own rice')
        ->assertJsonMissing(['name' => 'Foreign rice']);
});

it('returns 401 when a guest opens the item report', function () {
    $this->getJson('/api/v1/reports/item-activity?book=sales')->assertUnauthorized();
});

it('returns 422 when the item report query is invalid', function (string $query, string $field, string $message) {
    $company = Company::factory()->create();
    $user = memberWithRole($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/item-activity?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'book' => ['grain=month', 'book', 'The book field is required.'],
    'grain' => ['book=sales&grain=quarter', 'grain', 'The selected grain is invalid.'],
    'month' => ['book=sales&grain=day&month=13', 'month', 'The month field must be between 1 and 12.'],
    'year' => ['book=sales&year=1899', 'year', 'The year field must be between 1900 and 9999.'],
]);
