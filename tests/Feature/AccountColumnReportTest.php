<?php

use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\AccountColumnReport;
use App\Models\Company;
use App\Models\User;

function reportMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    return memberWithRole($company, $role);
}

function reportAccount(Company $company, string $code, string $name): Account
{
    return Account::factory()->create([
        'company_id' => $company->id,
        'code' => $code,
        'name' => $name,
        'type' => AccountType::Asset,
    ]);
}

it('saves a named report with accounts in the submitted order', function () {
    $company = Company::factory()->create();
    $owner = reportMember($company);
    $sales = reportAccount($company, '4000', 'Sales');
    $cash = reportAccount($company, '1000', 'Cash');

    $created = $this->actingAs($owner)->postJson('/api/v1/account-column-reports', [
        'name' => '  Cash and sales  ',
        'account_ids' => [$sales->id, $cash->id],
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Cash and sales')
        ->assertJsonPath('data.attributes.accounts.0', [
            'id' => (string) $sales->id,
            'code' => '4000',
            'name' => 'Sales',
        ])
        ->assertJsonPath('data.attributes.accounts.1.code', '1000');

    $id = (int) $created->json('data.id');

    $this->assertDatabaseHas('account_column_reports', [
        'id' => $id,
        'company_id' => $company->id,
        'name' => 'Cash and sales',
    ]);
    $this->assertDatabaseHas('account_column_report_account', [
        'account_column_report_id' => $id,
        'account_id' => $sales->id,
        'position' => 0,
    ]);
    $this->assertDatabaseHas('account_column_report_account', [
        'account_column_report_id' => $id,
        'account_id' => $cash->id,
        'position' => 1,
    ]);
});

it('requires a name and at least one account', function () {
    $company = Company::factory()->create();
    $owner = reportMember($company);

    $this->actingAs($owner)
        ->postJson('/api/v1/account-column-reports', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'The name field is required.')
        ->assertJsonPath('errors.account_ids.0', 'The account ids field is required.');
});

it('rejects a duplicate name in the same business', function () {
    $company = Company::factory()->create();
    $owner = reportMember($company);
    $cash = reportAccount($company, '1000', 'Cash');
    AccountColumnReport::factory()->create([
        'company_id' => $company->id,
        'name' => 'Cash columns',
    ]);

    $this->actingAs($owner)
        ->postJson('/api/v1/account-column-reports', [
            'name' => 'Cash columns',
            'account_ids' => [$cash->id],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'The name has already been taken.');
});

it('rejects an account from another business', function () {
    $company = Company::factory()->create();
    $owner = reportMember($company);
    $other = Company::factory()->create();
    $secret = reportAccount($other, '1000', 'Secret Cash');

    $this->actingAs($owner)
        ->postJson('/api/v1/account-column-reports', [
            'name' => 'Cash columns',
            'account_ids' => [$secret->id],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.account_ids.0', 'Choose accounts in this company.');
});

it('refuses staff who create a report', function () {
    $company = Company::factory()->create();
    $staff = reportMember($company, CompanyRole::Staff->value);
    $cash = reportAccount($company, '1000', 'Cash');

    $this->actingAs($staff)
        ->postJson('/api/v1/account-column-reports', [
            'name' => 'Cash columns',
            'account_ids' => [$cash->id],
        ])
        ->assertForbidden();
});

it('returns 403 when staff open a report', function () {
    $company = Company::factory()->create();
    $staff = reportMember($company, CompanyRole::Staff->value);
    $cash = reportAccount($company, '1000', 'Cash');
    $saved = AccountColumnReport::factory()->create([
        'company_id' => $company->id,
        'name' => 'Cash columns',
    ]);
    $saved->syncAccounts([$cash->id]);

    $this->actingAs($staff)
        ->getJson('/api/v1/account-column-reports')
        ->assertForbidden();

    $this->actingAs($staff)
        ->getJson('/api/v1/account-column-reports/'.$saved->id)
        ->assertForbidden();
});

it('replaces the name and account order', function () {
    $company = Company::factory()->create();
    $accountant = reportMember($company);
    $cash = reportAccount($company, '1000', 'Cash');
    $sales = reportAccount($company, '4000', 'Sales');
    $saved = AccountColumnReport::factory()->create([
        'company_id' => $company->id,
        'name' => 'Cash columns',
    ]);
    $saved->syncAccounts([$cash->id, $sales->id]);

    $updated = $this->actingAs($accountant)->putJson('/api/v1/account-column-reports/'.$saved->id, [
        'name' => 'Cash columns',
        'account_ids' => [$sales->id],
    ]);

    $updated->assertOk()
        ->assertJsonPath('data.attributes.name', 'Cash columns')
        ->assertJsonCount(1, 'data.attributes.accounts')
        ->assertJsonPath('data.attributes.accounts.0.code', '4000');

    $this->assertDatabaseHas('account_column_report_account', [
        'account_column_report_id' => $saved->id,
        'account_id' => $sales->id,
        'position' => 0,
    ]);
    $this->assertDatabaseMissing('account_column_report_account', [
        'account_column_report_id' => $saved->id,
        'account_id' => $cash->id,
    ]);
});

it('deletes a report', function () {
    $company = Company::factory()->create();
    $owner = reportMember($company);
    $cash = reportAccount($company, '1000', 'Cash');
    $saved = AccountColumnReport::factory()->create([
        'company_id' => $company->id,
        'name' => 'Cash columns',
    ]);
    $saved->syncAccounts([$cash->id]);

    $this->actingAs($owner)
        ->deleteJson('/api/v1/account-column-reports/'.$saved->id)
        ->assertNoContent();

    $this->assertDatabaseMissing('account_column_reports', ['id' => $saved->id]);
    $this->assertDatabaseMissing('account_column_report_account', [
        'account_column_report_id' => $saved->id,
    ]);
});

it('hides a saved report from another business', function () {
    $company = Company::factory()->create();
    $owner = reportMember($company);
    $other = Company::factory()->create();
    $secret = reportAccount($other, '1000', 'Secret Cash');
    $saved = AccountColumnReport::factory()->create([
        'company_id' => $other->id,
        'name' => 'Secret columns',
    ]);
    $saved->syncAccounts([$secret->id]);

    $this->actingAs($owner)
        ->getJson('/api/v1/account-column-reports/'.$saved->id)
        ->assertNotFound();
});

it('refuses a guest', function () {
    $this->getJson('/api/v1/account-column-reports')->assertUnauthorized();
});
