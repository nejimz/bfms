<?php

use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\User;

function chartMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    $user = User::factory()->create();
    $company->users()->attach($user->id, ['role' => $role]);

    return $user;
}

/**
 * @return array{date: string, account_id: int, particulars: string, amount: string}
 */
function chartEntryPayload(Account $account, string $amount = '150.00'): array
{
    return [
        'date' => '2026-10-15',
        'account_id' => $account->id,
        'particulars' => 'Office supplies',
        'amount' => $amount,
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
        ->assertJsonValidationErrors(['date', 'account_id', 'particulars', 'amount'])
        ->assertJsonPath('errors.date.0', 'The date field is required.')
        ->assertJsonPath('errors.account_id.0', 'The account id field is required.')
        ->assertJsonPath('errors.particulars.0', 'The particulars field is required.')
        ->assertJsonPath('errors.amount.0', 'The amount field is required.');
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

it('returns 403 when staff create an entry', function () {
    $company = Company::factory()->create();
    $staff = chartMember($company, CompanyRole::Staff->value);
    $cash = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Asset]);

    $this->actingAs($staff)
        ->postJson('/api/v1/chart-account-entries', chartEntryPayload($cash))
        ->assertForbidden();
});

/**
 * @return array{date: string, account_id: int, particulars: string, lines: list<array{name: string, quantity: string, amount: string}>}
 */
function chartEntryWithLines(Account $account): array
{
    return [
        'date' => '2026-10-15',
        'account_id' => $account->id,
        'particulars' => 'Northwind Supplies',
        'lines' => [
            ['name' => 'Rice', 'quantity' => '2', 'amount' => '100.00'],
            ['name' => 'Sugar', 'quantity' => '1.5', 'amount' => '45.25'],
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
            'lines' => [
                ['name' => 'Oil', 'quantity' => '1', 'amount' => '80.00'],
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

it('rejects an item line that has no name or no positive quantity or amount', function (array $line, string $error, string $message) {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $purchases = Account::factory()->create(['company_id' => $company->id, 'type' => AccountType::Expense]);

    $response = $this->actingAs($user)->postJson('/api/v1/chart-account-entries', [
        'date' => '2026-10-15',
        'account_id' => $purchases->id,
        'particulars' => 'Northwind Supplies',
        'lines' => [$line],
    ]);

    $response->assertUnprocessable();
    expect($response->json('errors')[$error][0])->toBe($message);
})->with([
    [['name' => ' ', 'quantity' => '1', 'amount' => '10.00'], 'lines.0.name', 'Enter the item name.'],
    [['name' => 'Rice', 'quantity' => '0', 'amount' => '10.00'], 'lines.0.quantity', 'Enter a quantity greater than zero.'],
    [['name' => 'Rice', 'quantity' => '1', 'amount' => '0.00'], 'lines.0.amount', 'Enter an amount greater than zero.'],
]);

it('returns 404 for an entry from another company', function () {
    $company = Company::factory()->create();
    $user = chartMember($company);
    $other = Company::factory()->create();
    $otherUser = chartMember($other);
    $secret = Account::factory()->create([
        'company_id' => $other->id,
        'code' => '9999',
        'name' => 'Secret Cash',
        'type' => AccountType::Asset,
    ]);

    $created = $this->actingAs($otherUser)->postJson('/api/v1/chart-account-entries', chartEntryPayload($secret));
    $id = $created->json('data.id');

    $this->actingAs($user)
        ->getJson("/api/v1/chart-account-entries/{$id}")
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson('/api/v1/chart-account-entries')
        ->assertOk()
        ->assertJsonMissing(['particulars' => 'Office supplies']);
});
