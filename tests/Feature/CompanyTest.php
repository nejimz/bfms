<?php

use App\Domain\Ledger\DefaultChart;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\Company;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

function companyMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    $user = User::factory()->create();
    $company->users()->attach($user->id, ['role' => $role]);

    return $user;
}

function openBooks(): void
{
    test()->withoutMiddleware(ValidateCsrfToken::class);
    test()->withCredentials();
    test()->withHeader('Origin', 'http://localhost:8000');
}

function carrySession(TestResponse $response): void
{
    $name = (string) config('session.cookie');
    $cookie = collect($response->headers->getCookies())->first(
        fn (Cookie $cookie) => $cookie->getName() === $name,
    );

    if ($cookie !== null) {
        test()->withUnencryptedCookie($name, $cookie->getValue());
    }
}

it('lets an owner open another business and keeps the books there', function () {
    $company = Company::factory()->create(['name' => 'Harbor Goods', 'currency' => 'PHP']);
    $owner = companyMember($company);
    Account::factory()->create([
        'company_id' => $company->id,
        'code' => '9999',
        'name' => 'Old Cash',
        'type' => AccountType::Asset,
    ]);

    openBooks();

    $created = $this->actingAs($owner)->postJson('/api/v1/companies', [
        'name' => '  Northwind  ',
        'currency' => 'usd',
        'description' => '  Wholesale imports.  ',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Northwind')
        ->assertJsonPath('data.attributes.description', 'Wholesale imports.')
        ->assertJsonPath('data.attributes.currency', 'USD');

    $newId = (int) $created->json('data.id');
    $created->assertSessionHas('current_company_id', $newId);
    carrySession($created);

    $this->assertDatabaseHas('companies', [
        'id' => $newId,
        'name' => 'Northwind',
        'description' => 'Wholesale imports.',
        'currency' => 'USD',
    ]);
    $this->assertDatabaseHas('company_user', [
        'company_id' => $newId,
        'user_id' => $owner->id,
        'role' => CompanyRole::Owner->value,
    ]);

    expect(Account::withoutGlobalScopes()->where('company_id', $newId)->count())
        ->toBe(count(DefaultChart::ACCOUNTS));

    $accounts = $this->actingAs($owner)->getJson('/api/v1/accounts')->assertOk();
    $codes = collect($accounts->json('data'))->pluck('attributes.code');

    expect($codes->all())->toContain('1000')->not->toContain('9999');

    $this->actingAs($owner)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.company.name', 'Northwind')
        ->assertJsonPath('data.attributes.role', CompanyRole::Owner->value)
        ->assertJsonPath('data.attributes.companies.0.name', 'Harbor Goods')
        ->assertJsonPath('data.attributes.companies.1.name', 'Northwind');
});

it('rejects a business without a name, description, or 3-letter currency', function () {
    $company = Company::factory()->create();
    $owner = companyMember($company);

    $this->actingAs($owner)->postJson('/api/v1/companies', [
        'name' => '   ',
        'currency' => 'peso',
        'description' => '   ',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'currency', 'description']);

    $this->actingAs($owner)->postJson('/api/v1/companies', [
        'name' => 'Northwind',
        'currency' => 'PHP',
        'description' => str_repeat('a', 1001),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['description']);
});

it('refuses a new business from someone who is not the owner', function (string $role) {
    $company = Company::factory()->create();
    $user = companyMember($company, $role);

    $this->actingAs($user)->postJson('/api/v1/companies', [
        'name' => 'Side Project',
        'currency' => 'PHP',
    ])->assertForbidden();
})->with([
    CompanyRole::Accountant->value,
    CompanyRole::Staff->value,
]);

it('switches the ledger to another business the user belongs to', function () {
    $first = Company::factory()->create(['name' => 'Alpha']);
    $second = Company::factory()->create(['name' => 'Beta']);
    $user = companyMember($first);
    $second->users()->attach($user->id, ['role' => CompanyRole::Accountant->value]);

    Account::factory()->create([
        'company_id' => $first->id,
        'code' => '1000',
        'name' => 'Alpha Cash',
        'type' => AccountType::Asset,
    ]);
    Account::factory()->create([
        'company_id' => $second->id,
        'code' => '2000',
        'name' => 'Beta Payable',
        'type' => AccountType::Liability,
    ]);

    openBooks();

    $this->actingAs($user)->getJson('/api/v1/accounts')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.code', '1000');

    $switchedCompany = $this->actingAs($user)->postJson('/api/v1/current-company', [
        'company_id' => $second->id,
    ])->assertOk()->assertJsonPath('data.attributes.name', 'Beta');

    $switchedCompany->assertSessionHas('current_company_id', $second->id);
    carrySession($switchedCompany);

    $switched = $this->actingAs($user)->getJson('/api/v1/accounts')->assertOk();
    $codes = collect($switched->json('data'))->pluck('attributes.code');

    expect($codes->all())->toBe(['2000']);

    $this->actingAs($user)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.company.name', 'Beta')
        ->assertJsonPath('data.attributes.role', CompanyRole::Accountant->value);
});

it('lists each business with its own chart and journals', function () {
    $alpha = Company::factory()->create(['name' => 'Alpha']);
    $beta = Company::factory()->create(['name' => 'Beta']);
    $hidden = Company::factory()->create(['name' => 'Hidden']);
    $user = companyMember($alpha);
    $beta->users()->attach($user->id, ['role' => CompanyRole::Owner->value]);

    Account::factory()->create(['company_id' => $alpha->id, 'code' => '1000']);
    Account::factory()->count(2)->create(['company_id' => $beta->id]);
    Account::factory()->create(['company_id' => $hidden->id, 'code' => '1000']);

    Journal::query()->create([
        'company_id' => $beta->id,
        'date' => '2026-10-01',
        'currency' => 'PHP',
        'status' => JournalStatus::Draft,
        'created_by' => $user->id,
    ]);

    $listed = $this->actingAs($user)->getJson('/api/v1/companies')->assertOk();
    $rows = collect($listed->json('data'))->keyBy('attributes.name');

    expect($rows->keys()->all())->toBe(['Alpha', 'Beta'])
        ->and($rows['Alpha']['attributes']['accounts_count'])->toBe(1)
        ->and($rows['Alpha']['attributes']['journals_count'])->toBe(0)
        ->and($rows['Beta']['attributes']['accounts_count'])->toBe(2)
        ->and($rows['Beta']['attributes']['journals_count'])->toBe(1)
        ->and($rows['Beta']['attributes']['role'])->toBe(CompanyRole::Owner->value);
});

it('lets an owner edit a business', function () {
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Old note',
        'currency' => 'PHP',
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        'name' => '  Harbor Goods Co  ',
        'description' => '  Retail goods and supplies.  ',
        'currency' => 'usd',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Harbor Goods Co')
        ->assertJsonPath('data.attributes.description', 'Retail goods and supplies.')
        ->assertJsonPath('data.attributes.currency', 'USD');

    $this->assertDatabaseHas('companies', [
        'id' => $company->id,
        'name' => 'Harbor Goods Co',
        'description' => 'Retail goods and supplies.',
        'currency' => 'USD',
    ]);
});

it('keeps the currency once a journal exists', function () {
    $company = Company::factory()->create(['currency' => 'PHP']);
    $owner = companyMember($company);
    Journal::query()->create([
        'company_id' => $company->id,
        'date' => '2026-10-01',
        'currency' => 'PHP',
        'status' => JournalStatus::Draft,
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'USD',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['currency']);

    expect($company->fresh()->currency)->toBe('PHP');

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        'name' => 'Harbor Market',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Harbor Market');
});

it('keeps the currency once a chart account entry exists', function () {
    $company = Company::factory()->create(['currency' => 'PHP']);
    $owner = companyMember($company);
    ChartAccountEntry::factory()->create([
        'company_id' => $company->id,
        'currency' => 'PHP',
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'USD',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['currency'])
        ->assertJsonPath('errors.currency.0', 'Currency stays fixed once a chart account entry exists.');

    expect($company->fresh()->currency)->toBe('PHP');
});

it('refuses an edit from someone who does not own that business', function (string $role) {
    $company = Company::factory()->create();
    $user = companyMember($company, $role);

    $this->actingAs($user)->patchJson("/api/v1/companies/{$company->id}", [
        'name' => 'Renamed',
        'description' => 'A different note.',
        'currency' => 'PHP',
    ])->assertForbidden();
})->with([
    CompanyRole::Accountant->value,
    CompanyRole::Staff->value,
]);

it('refuses to switch into a business the user does not belong to', function () {
    $company = Company::factory()->create();
    $user = companyMember($company);
    $other = Company::factory()->create();

    openBooks();

    $this->actingAs($user)->postJson('/api/v1/current-company', [
        'company_id' => $other->id,
    ])->assertForbidden();
});
