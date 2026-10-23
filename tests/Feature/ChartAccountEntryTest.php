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
