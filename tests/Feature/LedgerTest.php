<?php

use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use App\Models\User;

function memberOf(Company $company, string $role = CompanyRole::Owner->value): User
{
    return memberWithRole($company, $role);
}

function ledgerLines(Account $debit, Account $credit, string $debitAmount = '100.00', string $creditAmount = '100.00'): array
{
    return [
        'date' => '2026-10-15',
        'memo' => 'Test entry',
        'lines' => [
            ['account_id' => $debit->id, 'debit' => $debitAmount, 'credit' => '0.00'],
            ['account_id' => $credit->id, 'debit' => '0.00', 'credit' => $creditAmount],
        ],
    ];
}

it('does not post an unbalanced journal', function () {
    $company = Company::factory()->create();
    $user = memberOf($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'code' => '1000', 'type' => AccountType::Asset]);
    $sales = Account::factory()->create(['company_id' => $company->id, 'code' => '4000', 'type' => AccountType::Income]);

    $created = $this->actingAs($user)->postJson('/api/v1/journals', ledgerLines($cash, $sales, '100.00', '40.00'));

    $created->assertCreated();
    $id = $created->json('data.id');

    $this->actingAs($user)
        ->postJson("/api/v1/journals/{$id}/post")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines']);

    $journal = Journal::query()->findOrFail($id);

    expect($journal->status)->toBe(JournalStatus::Draft)
        ->and($journal->posted_at)->toBeNull()
        ->and(Journal::query()->where('status', JournalStatus::Posted)->count())->toBe(0);
});

it('shows a balanced journal on the trial balance', function () {
    $company = Company::factory()->create(['currency' => 'PHP']);
    $user = memberOf($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset]);
    $sales = Account::factory()->create(['company_id' => $company->id, 'code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income]);

    $created = $this->actingAs($user)->postJson('/api/v1/journals', ledgerLines($cash, $sales));
    $id = $created->json('data.id');

    $this->actingAs($user)
        ->postJson("/api/v1/journals/{$id}/post")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'posted')
        ->assertJsonPath('data.attributes.currency', 'PHP');

    $report = $this->actingAs($user)->getJson('/api/v1/reports/trial-balance?from=2026-10-01&to=2026-10-31');

    $report->assertOk()
        ->assertJsonPath('meta.currency', 'PHP')
        ->assertJsonPath('meta.debit_total', '100.00')
        ->assertJsonPath('meta.credit_total', '100.00')
        ->assertJsonPath('meta.difference', '0.00')
        ->assertJsonFragment(['code' => '1000', 'debit' => '100.00', 'credit' => '0.00'])
        ->assertJsonFragment(['code' => '4000', 'debit' => '0.00', 'credit' => '100.00']);
});

it('refuses edits to a posted journal', function () {
    $company = Company::factory()->create();
    $user = memberOf($company);
    $cash = Account::factory()->create(['company_id' => $company->id, 'code' => '1000', 'type' => AccountType::Asset]);
    $sales = Account::factory()->create(['company_id' => $company->id, 'code' => '4000', 'type' => AccountType::Income]);

    $created = $this->actingAs($user)->postJson('/api/v1/journals', ledgerLines($cash, $sales));
    $id = $created->json('data.id');
    $this->actingAs($user)->postJson("/api/v1/journals/{$id}/post")->assertOk();

    $this->actingAs($user)
        ->putJson("/api/v1/journals/{$id}", ledgerLines($cash, $sales, '5.00', '5.00'))
        ->assertConflict();

    expect(Journal::query()->findOrFail($id)->lines()->sum('debit_cents'))->toBe(10000);
});

it('hides another company ledger from the current user', function () {
    $company = Company::factory()->create();
    $user = memberOf($company);

    $other = Company::factory()->create();
    $secret = Account::factory()->create([
        'company_id' => $other->id,
        'code' => '9999',
        'name' => 'Secret Cash',
        'type' => AccountType::Asset,
    ]);
    $offset = Account::factory()->create([
        'company_id' => $other->id,
        'code' => '3999',
        'type' => AccountType::Equity,
    ]);
    $journal = Journal::query()->create([
        'company_id' => $other->id,
        'date' => '2026-10-01',
        'currency' => $other->currency,
        'status' => JournalStatus::Draft,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/accounts')
        ->assertOk()
        ->assertJsonMissing(['code' => '9999']);

    $this->actingAs($user)
        ->getJson("/api/v1/journals/{$journal->id}")
        ->assertNotFound();
});

it('returns 403 when staff post a journal', function (string $role) {
    $company = Company::factory()->create();
    $staff = memberOf($company, $role);
    $cash = Account::factory()->create(['company_id' => $company->id, 'code' => '1000', 'type' => AccountType::Asset]);
    $sales = Account::factory()->create(['company_id' => $company->id, 'code' => '4000', 'type' => AccountType::Income]);

    $this->actingAs($staff)
        ->postJson('/api/v1/journals', ledgerLines($cash, $sales))
        ->assertForbidden();
})->with([
    CompanyRole::Accountant->value,
    CompanyRole::Staff->value,
]);

it('returns 403 when a manager or staff member creates an account', function (string $role) {
    $company = Company::factory()->create();
    $user = memberOf($company, $role);

    $this->actingAs($user)->postJson('/api/v1/accounts', [
        'code' => '1200',
        'name' => 'Petty cash',
        'type' => AccountType::Asset->value,
    ])->assertForbidden();
})->with([
    CompanyRole::Accountant->value,
    CompanyRole::Staff->value,
]);

it('returns 403 when a manager or staff member opens the trial balance', function (string $role) {
    $company = Company::factory()->create();
    $user = memberOf($company, $role);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/trial-balance')
        ->assertForbidden();
})->with([
    CompanyRole::Accountant->value,
    CompanyRole::Staff->value,
]);
