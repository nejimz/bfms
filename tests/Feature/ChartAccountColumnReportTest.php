<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountEntryStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\AccountColumnReport;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Symfony\Component\HttpFoundation\Cookie;

function columnMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    $user = User::factory()->create();
    $company->users()->attach($user->id, ['role' => $role]);

    return $user;
}

/**
 * @param  list<Account>  $accounts
 */
function columnReport(Company $company, array $accounts, string $name = 'Cash columns'): AccountColumnReport
{
    $report = AccountColumnReport::factory()->create([
        'company_id' => $company->id,
        'name' => $name,
    ]);
    $report->syncAccounts(array_map(fn (Account $account): int => $account->id, $accounts));

    return $report;
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

it('lists one row per entry for accounts used in the year', function () {
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
    $saved = columnReport($company, [$cash, $rent, $float]);

    $report = $this->actingAs($staff)->getJson('/api/v1/reports/chart-account-columns?year=2026&report='.$saved->id);

    $report->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonCount(3, 'meta.columns')
        ->assertJsonPath('meta.year', 2026)
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
        ->assertJsonPath('meta.columns.2', [
            'id' => (string) $float->id,
            'code' => '9000',
            'name' => 'Float',
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
        ->assertJsonFragment([
            'date' => '2026-11-01',
            'particulars' => 'November float',
            'status' => 'draft',
            'account_id' => (string) $float->id,
            'amount' => '10.00',
        ])
        ->assertJsonMissing(['particulars' => 'Secret deposit'])
        ->assertJsonMissing(['particulars' => 'Prior year'])
        ->assertJsonMissing(['code' => '4000']);
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
    $saved = columnReport($company, [$cash]);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/chart-account-columns?year=2026&report='.$saved->id);

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
    $saved = columnReport($company, [$cash]);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/chart-account-columns?year=2026&sort=desc&report='.$saved->id);

    $report->assertOk()
        ->assertJsonPath('meta.sort', 'desc')
        ->assertJsonPath('data.0.particulars', 'Later')
        ->assertJsonPath('data.1.id', (string) $second->id)
        ->assertJsonPath('data.1.particulars', 'Same day second')
        ->assertJsonPath('data.2.id', (string) $first->id)
        ->assertJsonPath('data.2.particulars', 'Same day first');
});

it('defaults to the current year', function () {
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
    $saved = columnReport($company, [$cash]);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/chart-account-columns?report='.$saved->id);

    $report->assertOk()
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.sort', 'asc')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.particulars', 'September cash')
        ->assertJsonPath('data.1.particulars', 'October cash');
});

it('shows the business selected in the session', function () {
    $harbor = Company::factory()->create(['currency' => 'PHP']);
    $north = Company::factory()->create(['currency' => 'USD']);
    $user = columnMember($harbor);
    $north->users()->attach($user->id, ['role' => CompanyRole::Owner->value]);
    $cash = Account::factory()->create([
        'company_id' => $harbor->id,
        'code' => '1000',
        'name' => 'Cash',
        'type' => AccountType::Asset,
    ]);
    $sales = Account::factory()->create([
        'company_id' => $north->id,
        'code' => '4000',
        'name' => 'Sales',
        'type' => AccountType::Income,
    ]);
    columnEntry($harbor, $cash, $user, [
        'date' => '2026-03-01',
        'particulars' => 'Harbor cash',
        'amount_cents' => 1000,
    ]);
    columnEntry($north, $sales, $user, [
        'date' => '2026-04-01',
        'particulars' => 'North sales',
        'amount_cents' => 2000,
        'currency' => 'USD',
    ]);
    $saved = columnReport($north, [$sales], 'North columns');

    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withCredentials();
    $this->withHeader('Origin', 'http://localhost:8000');

    $switched = $this->actingAs($user)->postJson('/api/v1/current-company', [
        'company_id' => $north->id,
    ]);

    $switched->assertOk();

    $sessionCookie = (string) config('session.cookie');
    $cookie = collect($switched->headers->getCookies())->first(
        fn (Cookie $cookie) => $cookie->getName() === $sessionCookie,
    );

    if ($cookie !== null) {
        $this->withUnencryptedCookie($sessionCookie, $cookie->getValue());
    }

    $report = $this->actingAs($user)->getJson('/api/v1/reports/chart-account-columns?year=2026&report='.$saved->id);

    $report->assertOk()
        ->assertJsonPath('meta.currency', 'USD')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.particulars', 'North sales')
        ->assertJsonPath('meta.columns.0.code', '4000')
        ->assertJsonMissing(['particulars' => 'Harbor cash']);
});

it('shows a selected account with no entries and omits entries left off the report', function () {
    $company = Company::factory()->create();
    $user = columnMember($company);
    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'type' => AccountType::Asset,
    ]);
    $sales = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '4000',
        'name' => 'Sales',
        'type' => AccountType::Income,
    ]);
    $rent = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '5000',
        'name' => 'Rent',
        'type' => AccountType::Expense,
    ]);
    columnEntry($company, $cash, $user, [
        'date' => '2026-10-05',
        'particulars' => 'Drawer count',
        'amount_cents' => 2500,
    ]);
    columnEntry($company, $rent, $user, [
        'date' => '2026-10-20',
        'particulars' => 'October rent',
        'amount_cents' => 8000,
    ]);
    $saved = columnReport($company, [$sales, $cash]);

    $report = $this->actingAs($user)->getJson('/api/v1/reports/chart-account-columns?year=2026&report='.$saved->id);

    $report->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(2, 'meta.columns')
        ->assertJsonPath('meta.columns.0', [
            'id' => (string) $sales->id,
            'code' => '4000',
            'name' => 'Sales',
        ])
        ->assertJsonPath('meta.columns.1.code', '1000')
        ->assertJsonPath('data.0.particulars', 'Drawer count')
        ->assertJsonMissing(['particulars' => 'October rent']);
});

it('requires a report', function () {
    $company = Company::factory()->create();
    $user = columnMember($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/chart-account-columns')
        ->assertUnprocessable()
        ->assertJsonPath('errors.report.0', 'The report field is required.');
});

it('hides a report from another business', function () {
    $company = Company::factory()->create();
    $user = columnMember($company);
    $other = Company::factory()->create();
    $secret = Account::factory()->create([
        'company_id' => $other->id,
        'type' => AccountType::Asset,
    ]);
    $saved = columnReport($other, [$secret], 'Secret columns');

    $this->actingAs($user)
        ->getJson('/api/v1/reports/chart-account-columns?report='.$saved->id)
        ->assertNotFound();
});

it('rejects a year outside 1900 to 9999', function () {
    $company = Company::factory()->create();
    $user = columnMember($company);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/chart-account-columns?year=1899')
        ->assertUnprocessable()
        ->assertJsonPath('errors.year.0', 'The year field must be between 1900 and 9999.');
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
