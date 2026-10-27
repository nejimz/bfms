<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountEntryStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;

function chartMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    return memberWithRole($company, $role);
}

/**
 * @return array{date: string, account_id: int, particulars: string, amount: string, cash: string, credit: string}
 */
function chartEntryPayload(Account $account, string $amount = '150.00'): array
{
    return [
        'date' => '2026-10-15',
        'account_id' => $account->id,
        'particulars' => 'Office supplies',
        'amount' => $amount,
        'cash' => $amount,
        'credit' => '0.00',
    ];
}

function postChartEntry(User $user, Account $account): string
{
    $created = test()->actingAs($user)->postJson('/api/v1/chart-account-entries', chartEntryPayload($account));
    $created->assertCreated();
    $id = (string) $created->json('data.id');

    test()->actingAs($user)->postJson("/api/v1/chart-account-entries/{$id}/post")->assertOk();

    return $id;
}

it('stores a draft and lists it with the account', function () {
    $company = Company::factory()->create(['currency' => 'PHP']);
    $user = chartMember($company);
    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'type' => AccountType::Asset,
    ]);

    $created = $this->actingAs($user)->postJson('/api/v1/chart-account-entries', [
        ...chartEntryPayload($cash),
        'status' => 'posted',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.status', 'draft')
        ->assertJsonPath('data.attributes.amount', '150.00')
        ->assertJsonPath('data.attributes.currency', 'PHP')
        ->assertJsonPath('data.attributes.particulars', 'Office supplies')
        ->assertJsonPath('data.attributes.date', '2026-10-15')
        ->assertJsonPath('data.relationships.account.data.id', (string) $cash->id)
        ->assertJsonPath('included.0.attributes.code', '1000')
        ->assertJsonPath('included.0.attributes.name', 'Cash');

    $id = $created->json('data.id');

    $this->assertDatabaseHas('chart_account_entries', [
        'id' => $id,
        'company_id' => $company->id,
        'account_id' => $cash->id,
        'amount_cents' => 15000,
        'cash_cents' => 15000,
        'credit_cents' => 0,
        'reference' => null,
        'status' => 'draft',
        'posted_by' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/chart-account-entries')
        ->assertOk()
        ->assertJsonPath('data.0.id', (string) $id)
        ->assertJsonPath('data.0.attributes.particulars', 'Office supplies')
        ->assertJsonPath('included.0.attributes.code', '1000');
});

it('lists every entry when the register has more than 100', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $account = Account::factory()->create([
        'company_id' => $company->id,
    ]);
    $entries = ChartAccountEntry::factory()->count(101)->create([
        'company_id' => $company->id,
        'account_id' => $account->id,
        'created_by' => $user->id,
        'date' => '2026-10-15',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/chart-account-entries')
        ->assertOk()
        ->assertJsonCount(101, 'data')
        ->assertJsonFragment(['id' => (string) $entries->first()->id]);
});

it('pages one book and keeps the next page', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $sales = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '4000',
        'name' => 'Sales',
        'type' => AccountType::Income,
    ]);
    $purchases = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '5100',
        'name' => 'Purchase Expenses',
        'type' => AccountType::Expense,
    ]);
    $entries = ChartAccountEntry::factory()->count(21)->create([
        'company_id' => $company->id,
        'account_id' => $sales->id,
        'created_by' => $user->id,
        'date' => '2026-10-15',
    ]);
    $entries->first()->update(['status' => ChartAccountEntryStatus::Posted]);
    ChartAccountEntry::factory()->create([
        'company_id' => $company->id,
        'account_id' => $purchases->id,
        'created_by' => $user->id,
        'date' => '2026-10-16',
        'particulars' => 'Shop stock',
    ]);

    $first = $this->actingAs($user)->getJson('/api/v1/chart-account-entries?account=4000&page=1');

    $first->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', 21)
        ->assertJsonPath('meta.drafts', 20)
        ->assertJsonPath('included.0.attributes.code', '4000')
        ->assertJsonMissing(['particulars' => 'Shop stock']);

    $this->actingAs($user)
        ->getJson('/api/v1/chart-account-entries?account=4000&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $entries->first()->id)
        ->assertJsonPath('data.0.attributes.status', 'posted')
        ->assertJsonPath('meta.drafts', 20);
});

it('returns 422 when the entry list account is not in this business', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);

    $this->actingAs($user)
        ->getJson('/api/v1/chart-account-entries?account=4000')
        ->assertUnprocessable()
        ->assertJsonPath('errors.account.0', 'The selected account is invalid.');
});

it('returns 422 when the entry list page is not a page number', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);

    $this->actingAs($user)
        ->getJson('/api/v1/chart-account-entries?page=0')
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'The page field must be at least 1.');
});

it('updates a draft entry', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'code' => '1000', 'type' => AccountType::Asset]);
    $rent = Account::factory()->create(['company_id' => $company->id, 'code' => '5000', 'name' => 'Rent', 'type' => AccountType::Expense]);

    $created = $this->actingAs($user)->postJson('/api/v1/chart-account-entries', chartEntryPayload($cash));
    $id = $created->json('data.id');

    $this->actingAs($user)
        ->putJson("/api/v1/chart-account-entries/{$id}", [
            'date' => '2026-10-20',
            'account_id' => $rent->id,
            'particulars' => 'October rent',
            'amount' => '80.50',
            'cash' => '80.50',
            'credit' => '0.00',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.date', '2026-10-20')
        ->assertJsonPath('data.attributes.particulars', 'October rent')
        ->assertJsonPath('data.attributes.amount', '80.50')
        ->assertJsonPath('data.attributes.account_id', (string) $rent->id);

    $this->assertDatabaseHas('chart_account_entries', [
        'id' => $id,
        'account_id' => $rent->id,
        'amount_cents' => 8050,
        'particulars' => 'October rent',
    ]);
});

it('deletes a draft entry', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);

    $created = $this->actingAs($user)->postJson('/api/v1/chart-account-entries', chartEntryPayload($cash));
    $id = $created->json('data.id');

    $this->actingAs($user)
        ->deleteJson("/api/v1/chart-account-entries/{$id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('chart_account_entries', ['id' => $id]);
});

it('returns 409 when a posted entry is edited', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);
    $id = postChartEntry($user, $cash);

    $this->actingAs($user)
        ->putJson("/api/v1/chart-account-entries/{$id}", chartEntryPayload($cash, '5.00'))
        ->assertConflict()
        ->assertJsonPath('message', 'Posted chart account entries cannot be edited.');

    expect(ChartAccountEntry::query()->findOrFail($id)->amount_cents)->toBe(15000);
});

it('returns 409 when a posted entry is deleted', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);
    $id = postChartEntry($user, $cash);

    $this->actingAs($user)
        ->deleteJson("/api/v1/chart-account-entries/{$id}")
        ->assertConflict()
        ->assertJsonPath('message', 'Posted chart account entries cannot be deleted.');

    $this->assertModelExists(ChartAccountEntry::query()->findOrFail($id));
});

it('returns 409 when a posted entry is posted again', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);
    $id = postChartEntry($user, $cash);

    $this->actingAs($user)
        ->postJson("/api/v1/chart-account-entries/{$id}/post")
        ->assertConflict()
        ->assertJsonPath('message', 'This chart account entry is already posted.');
});

it('leaves the trial balance unchanged after an entry is posted', function () {
    $company = Company::factory()->create(['currency' => 'PHP']);
    $user = chartMember($company);
    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'type' => AccountType::Asset,
    ]);
    $id = postChartEntry($user, $cash);

    expect(ChartAccountEntry::query()->findOrFail($id)->status->value)->toBe('posted');

    $this->actingAs($user)
        ->getJson('/api/v1/reports/trial-balance?from=2026-10-01&to=2026-10-31')
        ->assertOk()
        ->assertJsonPath('meta.debit_total', '0.00')
        ->assertJsonPath('meta.credit_total', '0.00')
        ->assertJsonCount(0, 'data');

    $this->assertDatabaseCount('journals', 0);
});

it('returns 422 when the entry payload is empty', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);

    $this->actingAs($user)
        ->postJson('/api/v1/chart-account-entries', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date', 'account_id', 'particulars', 'amount', 'cash', 'credit'])
        ->assertJsonPath('errors.date.0', 'The date field is required.')
        ->assertJsonPath('errors.account_id.0', 'The account id field is required.')
        ->assertJsonPath('errors.particulars.0', 'The particulars field is required.')
        ->assertJsonPath('errors.amount.0', 'The amount field is required.')
        ->assertJsonPath('errors.cash.0', 'Enter the cash amount.')
        ->assertJsonPath('errors.credit.0', 'Enter the credit amount.');
});

it('rejects an amount that is not a positive value', function (string $amount, string $message) {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);

    $this->actingAs($user)
        ->postJson('/api/v1/chart-account-entries', chartEntryPayload($cash, $amount))
        ->assertUnprocessable()
        ->assertJsonPath('errors.amount.0', $message);
})->with([
    ['0.00', 'Enter an amount greater than zero.'],
    ['-1.00', 'Enter an amount with up to 2 decimal places.'],
]);

it('returns 422 when the account belongs to another company', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $other = Company::factory()->create();
    $foreign = Account::factory()->create(['company_id' => $other->id, 'type' => AccountType::Asset]);

    $this->actingAs($user)
        ->postJson('/api/v1/chart-account-entries', chartEntryPayload($foreign))
        ->assertUnprocessable()
        ->assertJsonPath('errors.account_id.0', 'Choose an account in this company.');
});

it('returns 422 when the account is inactive', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create([
        'company_id' => $company->id,
        'type' => AccountType::Asset,
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/chart-account-entries', chartEntryPayload($cash))
        ->assertUnprocessable()
        ->assertJsonPath('errors.account_id.0', 'That account is inactive.');
});

it('lets staff post an entry for an assigned business', function () {
    $company = Company::factory()->create();
    $staff = chartMember($company, CompanyRole::Staff->value);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);

    $id = postChartEntry($staff, $cash);

    $this->assertDatabaseHas('chart_account_entries', [
        'id' => $id,
        'status' => 'posted',
        'created_by' => $staff->id,
        'posted_by' => $staff->id,
    ]);
});

it('lets a manager post an entry without a business assignment', function () {
    $company = Company::factory()->create();
    $manager = User::factory()->manager()->create();
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);

    $id = postChartEntry($manager, $cash);

    $this->assertDatabaseHas('chart_account_entries', [
        'id' => $id,
        'status' => 'posted',
        'posted_by' => $manager->id,
    ]);
});

/**
 * @return array{date: string, account_id: int, particulars: string, cash: string, credit: string, lines: list<array{item_id: int, name?: string, quantity: string, amount: string}>}
 */
function chartEntryWithLines(Account $account): array
{
    $rice = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);
    $sugar = Item::factory()->create(['name' => 'Sugar', 'unit' => 'kg']);

    return [
        'date' => '2026-10-15',
        'account_id' => $account->id,
        'particulars' => 'Northwind Supplies',
        'cash' => '145.25',
        'credit' => '0.00',
        'lines' => [
            ['item_id' => $rice->id, 'name' => 'Not Rice', 'quantity' => '2', 'amount' => '100.00'],
            ['item_id' => $sugar->id, 'quantity' => '1.5', 'amount' => '45.25'],
        ],
    ];
}

it('stores item lines and sets the amount to their sum', function () {
    $company = Company::factory()->create(['currency' => 'PHP']);
    $user = chartMember($company);
    $purchases = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '5100',
        'name' => 'Purchase Expenses',
        'type' => AccountType::Expense,
    ]);

    $created = $this->actingAs($user)->postJson('/api/v1/chart-account-entries', chartEntryWithLines($purchases));

    $created->assertCreated()
        ->assertJsonPath('data.attributes.amount', '145.25')
        ->assertJsonPath('data.attributes.particulars', 'Northwind Supplies')
        ->assertJsonPath('included.1.attributes.name', 'Rice')
        ->assertJsonPath('included.1.attributes.quantity', '2')
        ->assertJsonPath('included.1.attributes.amount', '100.00')
        ->assertJsonPath('included.2.attributes.name', 'Sugar')
        ->assertJsonPath('included.2.attributes.quantity', '1.5')
        ->assertJsonPath('included.2.attributes.amount', '45.25');

    $id = $created->json('data.id');

    $this->assertDatabaseHas('chart_account_entries', [
        'id' => $id,
        'amount_cents' => 14525,
    ]);
    $this->assertDatabaseHas('chart_account_entry_lines', [
        'chart_account_entry_id' => $id,
        'position' => 0,
        'item_id' => Item::query()->where('name', 'Rice')->value('id'),
        'name' => 'Rice',
        'quantity' => '2.000',
        'amount_cents' => 10000,
    ]);
    $this->assertDatabaseHas('chart_account_entry_lines', [
        'chart_account_entry_id' => $id,
        'position' => 1,
        'name' => 'Sugar',
        'amount_cents' => 4525,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/chart-account-entries')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.amount', '145.25')
        ->assertJsonPath('included.1.attributes.name', 'Rice');
});

it('replaces item lines when a draft is updated', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $purchases = Account::factory()->create(['company_id' => $company->id, 'code' => '5100', 'type' => AccountType::Expense]);

    $created = $this->actingAs($user)->postJson('/api/v1/chart-account-entries', chartEntryWithLines($purchases));
    $id = $created->json('data.id');

    $this->actingAs($user)
        ->putJson("/api/v1/chart-account-entries/{$id}", [
            'date' => '2026-10-16',
            'account_id' => $purchases->id,
            'particulars' => 'Northwind Supplies',
            'cash' => '80.00',
            'credit' => '0.00',
            'lines' => [
                ['item_id' => Item::factory()->create(['name' => 'Oil', 'unit' => 'liter'])->id, 'quantity' => '1', 'amount' => '80.00'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.amount', '80.00')
        ->assertJsonPath('included.1.attributes.name', 'Oil');

    $this->assertDatabaseMissing('chart_account_entry_lines', [
        'chart_account_entry_id' => $id,
        'name' => 'Rice',
    ]);
    $this->assertDatabaseHas('chart_account_entry_lines', [
        'chart_account_entry_id' => $id,
        'name' => 'Oil',
        'amount_cents' => 8000,
    ]);
});

it('rejects an item line that has no item or no positive quantity or amount', function (string $case, string $error, string $message) {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $purchases = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Expense]);
    $rice = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);
    $line = match ($case) {
        'missing' => ['quantity' => '1', 'amount' => '10.00'],
        'unknown' => ['item_id' => $rice->id + 1000, 'quantity' => '1', 'amount' => '10.00'],
        'quantity' => ['item_id' => $rice->id, 'quantity' => '0', 'amount' => '10.00'],
        default => ['item_id' => $rice->id, 'quantity' => '1', 'amount' => '0.00'],
    };

    $response = $this->actingAs($user)->postJson('/api/v1/chart-account-entries', [
        'date' => '2026-10-15',
        'account_id' => $purchases->id,
        'particulars' => 'Northwind Supplies',
        'cash' => '10.00',
        'credit' => '0.00',
        'lines' => [$line],
    ]);

    $response->assertUnprocessable();
    expect($response->json('errors')[$error][0])->toBe($message);
})->with([
    'missing item' => ['missing', 'lines.0.item_id', 'Choose an item.'],
    'unknown item' => ['unknown', 'lines.0.item_id', 'Choose an item.'],
    'quantity' => ['quantity', 'lines.0.quantity', 'Enter a quantity greater than zero.'],
    'amount' => ['amount', 'lines.0.amount', 'Enter an amount greater than zero.'],
]);

it('stores a reference and a partial cash and credit split', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $purchases = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '5100',
        'type' => AccountType::Expense,
    ]);

    $created = $this->actingAs($user)->postJson('/api/v1/chart-account-entries', [
        ...chartEntryWithLines($purchases),
        'reference' => '  DR-1042  ',
        'cash' => '45.25',
        'credit' => '100.00',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.reference', 'DR-1042')
        ->assertJsonPath('data.attributes.amount', '145.25')
        ->assertJsonPath('data.attributes.cash', '45.25')
        ->assertJsonPath('data.attributes.credit', '100.00');

    $this->assertDatabaseHas('chart_account_entries', [
        'id' => $created->json('data.id'),
        'reference' => 'DR-1042',
        'amount_cents' => 14525,
        'cash_cents' => 4525,
        'credit_cents' => 10000,
    ]);
});

it('stores a blank reference as empty', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);

    $created = $this->actingAs($user)->postJson('/api/v1/chart-account-entries', [
        ...chartEntryPayload($cash),
        'reference' => '   ',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.reference', null);

    $this->assertDatabaseHas('chart_account_entries', [
        'id' => $created->json('data.id'),
        'reference' => null,
    ]);
});

it('returns 422 when cash and credit do not equal the total', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $purchases = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Expense]);

    $this->actingAs($user)
        ->postJson('/api/v1/chart-account-entries', [
            ...chartEntryWithLines($purchases),
            'cash' => '50.00',
            'credit' => '50.00',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.cash.0', 'Cash and credit must equal the total.');

    $this->assertDatabaseCount('chart_account_entries', 0);
});

it('returns 422 when cash is not an amount', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);

    $this->actingAs($user)
        ->postJson('/api/v1/chart-account-entries', [
            ...chartEntryPayload($cash),
            'cash' => 'paid',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.cash.0', 'Enter an amount with up to 2 decimal places.');
});

it('returns 422 when the reference is longer than 50 characters', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);

    $this->actingAs($user)
        ->postJson('/api/v1/chart-account-entries', [
            ...chartEntryPayload($cash),
            'reference' => str_repeat('A', 51),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.reference.0', 'Enter a reference number of at most 50 characters.');
});

it('corrects the cash and credit on a posted entry', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $sales = Account::factory()->create([
        'company_id' => $company->id,
        'code' => '4000',
        'type' => AccountType::Income,
    ]);
    $id = postChartEntry($user, $sales);

    $this->actingAs($user)
        ->putJson("/api/v1/chart-account-entries/{$id}", [
            'reference' => 'OR-19',
            'cash' => '40.00',
            'credit' => '110.00',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.reference', 'OR-19')
        ->assertJsonPath('data.attributes.amount', '150.00')
        ->assertJsonPath('data.attributes.cash', '40.00')
        ->assertJsonPath('data.attributes.credit', '110.00')
        ->assertJsonPath('data.attributes.status', 'posted');

    $this->assertDatabaseHas('chart_account_entries', [
        'id' => $id,
        'reference' => 'OR-19',
        'amount_cents' => 15000,
        'cash_cents' => 4000,
        'credit_cents' => 11000,
        'particulars' => 'Office supplies',
        'status' => 'posted',
    ]);
});

it('returns 422 when a posted correction does not equal the total', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);
    $id = postChartEntry($user, $cash);

    $this->actingAs($user)
        ->putJson("/api/v1/chart-account-entries/{$id}", [
            'cash' => '10.00',
            'credit' => '10.00',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.cash.0', 'Cash and credit must equal the total.');

    $this->assertDatabaseHas('chart_account_entries', [
        'id' => $id,
        'cash_cents' => 15000,
        'credit_cents' => 0,
    ]);
});

it('returns 404 for an entry from another company', function () {
    $company = Company::factory()->create();
    $user = chartMember($company, CompanyRole::Staff->value);
    $other = Company::factory()->create();
    $otherUser = chartMember($other, CompanyRole::Staff->value);
    $secret = Account::factory()->create([
        'company_id' => $other->id,
        'code' => '9999',
        'name' => 'Secret Cash',
        'type' => AccountType::Asset,
    ]);

    $created = $this->actingAs($otherUser)->postJson('/api/v1/chart-account-entries', chartEntryPayload($secret));
    $created->assertCreated();
    $id = $created->json('data.id');

    $this->actingAs($user)
        ->getJson("/api/v1/chart-account-entries/{$id}")
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson('/api/v1/chart-account-entries')
        ->assertOk()
        ->assertJsonMissing(['particulars' => 'Office supplies']);
});
