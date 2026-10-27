<?php

use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\JournalStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\ChartAccountEntry;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Journal;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

function companyMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    return memberWithRole($company, $role);
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
    $this->assertDatabaseMissing('company_user', [
        'company_id' => $newId,
        'user_id' => $owner->id,
    ]);

    $accounts = $this->actingAs($owner)->getJson('/api/v1/accounts')->assertOk();

    expect(collect($accounts->json('data'))->map(fn (array $row): array => [
        $row['attributes']['code'],
        $row['attributes']['name'],
        $row['attributes']['type'],
    ])->all())->toBe([
        ['4000', 'Sales', 'income'],
        ['5000', 'Operation Expenses', 'expense'],
        ['5100', 'Purchase Expenses', 'expense'],
    ]);

    $this->actingAs($owner)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.company.name', 'Northwind')
        ->assertJsonPath('data.attributes.role', UserRole::Admin->value)
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

it('switches the ledger to another business', function () {
    $first = Company::factory()->create(['name' => 'Alpha']);
    $second = Company::factory()->create(['name' => 'Beta']);
    $user = User::factory()->manager()->create();

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
        ->assertJsonPath('data.attributes.role', UserRole::Manager->value);
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

    expect($rows->keys()->all())->toBe(['Alpha', 'Beta', 'Hidden'])
        ->and($rows['Alpha']['attributes']['accounts_count'])->toBe(1)
        ->and($rows['Alpha']['attributes']['journals_count'])->toBe(0)
        ->and($rows['Beta']['attributes']['accounts_count'])->toBe(2)
        ->and($rows['Beta']['attributes']['journals_count'])->toBe(1)
        ->and($rows['Hidden']['attributes']['accounts_count'])->toBe(1)
        ->and($rows['Hidden']['attributes']['journals_count'])->toBe(0);
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

it('stores a classification on a new business', function () {
    $company = Company::factory()->create();
    $owner = companyMember($company);
    $classification = Classification::factory()->create(['name' => 'Retail']);

    $created = $this->actingAs($owner)->postJson('/api/v1/companies', [
        'name' => 'Northwind',
        'currency' => 'PHP',
        'description' => 'Wholesale imports.',
        'classification_id' => $classification->id,
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.classification_id', (string) $classification->id)
        ->assertJsonPath('data.attributes.classification_name', 'Retail');

    $this->assertDatabaseHas('companies', [
        'id' => (int) $created->json('data.id'),
        'classification_id' => $classification->id,
    ]);
});

it('lists each business with its classification', function () {
    $classification = Classification::factory()->create(['name' => 'Retail']);
    $company = Company::factory()->create([
        'name' => 'Harbor',
        'classification_id' => $classification->id,
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->getJson('/api/v1/companies')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.classification_id', (string) $classification->id)
        ->assertJsonPath('data.0.attributes.classification_name', 'Retail');
});

it('lets an owner change or clear a business classification', function () {
    $retail = Classification::factory()->create(['name' => 'Retail']);
    $wholesale = Classification::factory()->create(['name' => 'Wholesale']);
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
        'classification_id' => $retail->id,
    ]);
    $owner = companyMember($company);
    $payload = [
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ];

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        ...$payload,
        'classification_id' => $wholesale->id,
    ])->assertOk()
        ->assertJsonPath('data.attributes.classification_name', 'Wholesale');

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        ...$payload,
        'classification_id' => null,
    ])->assertOk()
        ->assertJsonPath('data.attributes.classification_id', null)
        ->assertJsonPath('data.attributes.classification_name', null);

    expect($company->fresh()->classification_id)->toBeNull();
});

it('keeps the classification when a business edit omits it', function () {
    $classification = Classification::factory()->create(['name' => 'Retail']);
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
        'classification_id' => $classification->id,
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        'name' => 'Harbor Market',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Harbor Market')
        ->assertJsonPath('data.attributes.classification_id', (string) $classification->id);

    expect($company->fresh()->classification_id)->toBe($classification->id);
});

it('rejects a business classification that is not in the list', function () {
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
        'classification_id' => 9999,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.classification_id.0', 'Choose a classification from the list.');

    expect($company->fresh()->classification_id)->toBeNull();
});

it('refuses to switch into a business the user does not belong to', function () {
    $company = Company::factory()->create();
    $user = companyMember($company, CompanyRole::Staff->value);
    $other = Company::factory()->create();

    openBooks();

    $this->actingAs($user)->postJson('/api/v1/current-company', [
        'company_id' => $other->id,
    ])->assertForbidden();
});

it('stores a location on a new business', function () {
    $company = Company::factory()->create();
    $owner = companyMember($company);
    $location = Location::factory()->create(['name' => 'Cebu']);

    $created = $this->actingAs($owner)->postJson('/api/v1/companies', [
        'name' => 'Northwind',
        'currency' => 'PHP',
        'description' => 'Wholesale imports.',
        'location_id' => $location->id,
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.location_id', (string) $location->id)
        ->assertJsonPath('data.attributes.location_name', 'Cebu')
        ->assertJsonPath('data.attributes.logo_url', null);

    $this->assertDatabaseHas('companies', [
        'id' => (int) $created->json('data.id'),
        'location_id' => $location->id,
        'logo_path' => null,
    ]);
});

it('lists each business with its location', function () {
    $location = Location::factory()->create(['name' => 'Cebu']);
    $company = Company::factory()->create([
        'name' => 'Harbor',
        'location_id' => $location->id,
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->getJson('/api/v1/companies')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.location_id', (string) $location->id)
        ->assertJsonPath('data.0.attributes.location_name', 'Cebu')
        ->assertJsonPath('data.0.attributes.logo_url', null);
});

it('lets an owner change or clear a business location', function () {
    $cebu = Location::factory()->create(['name' => 'Cebu']);
    $manila = Location::factory()->create(['name' => 'Manila']);
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
        'location_id' => $cebu->id,
    ]);
    $owner = companyMember($company);
    $payload = [
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ];

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        ...$payload,
        'location_id' => $manila->id,
    ])->assertOk()
        ->assertJsonPath('data.attributes.location_name', 'Manila');

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        ...$payload,
        'location_id' => null,
    ])->assertOk()
        ->assertJsonPath('data.attributes.location_id', null)
        ->assertJsonPath('data.attributes.location_name', null);

    expect($company->fresh()->location_id)->toBeNull();
});

it('keeps the location when a business edit omits it', function () {
    $location = Location::factory()->create(['name' => 'Cebu']);
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
        'location_id' => $location->id,
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        'name' => 'Harbor Market',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Harbor Market')
        ->assertJsonPath('data.attributes.location_id', (string) $location->id);

    expect($company->fresh()->location_id)->toBe($location->id);
});

it('rejects a business location that is not in the list', function () {
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
        'location_id' => 9999,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.location_id.0', 'Choose a location from the list.');

    expect($company->fresh()->location_id)->toBeNull();
});

/**
 * @return array<string, string>
 */
function businessFields(Company $company): array
{
    return [
        'name' => $company->name,
        'description' => (string) $company->description,
        'currency' => $company->currency,
    ];
}

it('stores a logo and keeps it when the business is renamed', function () {
    Storage::fake('public');
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ]);
    $owner = companyMember($company);

    $saved = $this->actingAs($owner)->post("/api/v1/companies/{$company->id}", [
        '_method' => 'PATCH',
        ...businessFields($company),
        'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
    ]);

    $saved->assertOk();
    $path = $company->fresh()->logo_path;
    expect($path)->toBeString()
        ->and($saved->json('data.attributes.logo_url'))->toBe('/storage/'.$path);
    Storage::disk('public')->assertExists($path);

    $this->actingAs($owner)->patchJson("/api/v1/companies/{$company->id}", [
        ...businessFields($company),
        'name' => 'Harbor Market',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Harbor Market')
        ->assertJsonPath('data.attributes.logo_url', '/storage/'.$path);

    Storage::disk('public')->assertExists($path);
});

it('replaces the business logo and deletes the previous file', function () {
    Storage::fake('public');
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->post("/api/v1/companies/{$company->id}", [
        '_method' => 'PATCH',
        ...businessFields($company),
        'logo' => UploadedFile::fake()->image('first.png', 64, 64),
    ])->assertOk();

    $previous = $company->fresh()->logo_path;

    $replaced = $this->actingAs($owner)->post("/api/v1/companies/{$company->id}", [
        '_method' => 'PATCH',
        ...businessFields($company),
        'logo' => UploadedFile::fake()->image('second.png', 64, 64),
    ]);

    $replaced->assertOk();
    $current = $company->fresh()->logo_path;

    expect($current)->not->toBe($previous)
        ->and($replaced->json('data.attributes.logo_url'))->toBe('/storage/'.$current);
    Storage::disk('public')->assertMissing($previous);
    Storage::disk('public')->assertExists($current);
});

it('removes the business logo when asked and ignores a submitted file path', function () {
    Storage::fake('public');
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->post("/api/v1/companies/{$company->id}", [
        '_method' => 'PATCH',
        ...businessFields($company),
        'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
    ])->assertOk();

    $path = $company->fresh()->logo_path;

    $this->actingAs($owner)->post("/api/v1/companies/{$company->id}", [
        '_method' => 'PATCH',
        ...businessFields($company),
        'remove_logo' => '1',
        'logo_path' => 'companies/evil.php',
    ])->assertOk()
        ->assertJsonPath('data.attributes.logo_url', null);

    expect($company->fresh()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('rejects a business logo that is not a JPG, PNG, or WebP image', function (string $name, UploadedFile $logo) {
    Storage::fake('public');
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->post("/api/v1/companies/{$company->id}", [
        '_method' => 'PATCH',
        ...businessFields($company),
        'logo' => $logo,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.logo.0', 'Use a JPG, PNG, or WebP logo.');

    expect($company->fresh()->logo_path)->toBeNull();
})->with([
    'pdf' => ['pdf', UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')],
    'gif' => ['gif', UploadedFile::fake()->image('mark.gif', 32, 32)],
]);

it('rejects a business logo larger than 2 MB', function () {
    Storage::fake('public');
    $company = Company::factory()->create([
        'name' => 'Harbor Goods',
        'description' => 'Retail goods and supplies.',
        'currency' => 'PHP',
    ]);
    $owner = companyMember($company);

    $this->actingAs($owner)->post("/api/v1/companies/{$company->id}", [
        '_method' => 'PATCH',
        ...businessFields($company),
        'logo' => UploadedFile::fake()->image('logo.png')->size(2049),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.logo.0', 'The logo must be 2 MB or smaller.');

    expect($company->fresh()->logo_path)->toBeNull();
});

it('lets an admin open the books when no business exists', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.company', null)
        ->assertJsonPath('data.attributes.companies', []);

    $this->actingAs($admin)->getJson('/api/v1/companies')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('still refuses a manager or staff member when no business exists', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->getJson('/api/v1/user')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not belong to a company.');
})->with([
    UserRole::Manager->value,
    UserRole::Staff->value,
]);
