<?php

use App\Enums\CompanyRole;
use App\Models\Category;
use App\Models\Classification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function classificationMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    return memberWithRole($company, $role);
}

it('creates a classification and trims the name', function () {
    $company = Company::factory()->create();
    $owner = classificationMember($company);

    $created = $this->actingAs($owner)->postJson('/api/v1/classifications', [
        'name' => '  Retail  ',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Retail')
        ->assertJsonPath('data.attributes.businesses_count', 0);

    $this->assertDatabaseHas('classifications', [
        'id' => (int) $created->json('data.id'),
        'name' => 'Retail',
    ]);
});

it('rejects a classification without a name or with a name over 255 characters', function () {
    $company = Company::factory()->create();
    $owner = classificationMember($company);

    $this->actingAs($owner)->postJson('/api/v1/classifications', [
        'name' => '   ',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Enter a classification name.');

    $this->actingAs($owner)->postJson('/api/v1/classifications', [
        'name' => str_repeat('a', 256),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'The classification name must be 255 characters or fewer.');
});

it('rejects a classification name that already exists in any letter case', function () {
    $company = Company::factory()->create();
    $owner = classificationMember($company);
    Classification::factory()->create(['name' => 'Retail']);

    $this->actingAs($owner)->postJson('/api/v1/classifications', [
        'name' => 'retail',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'A classification with this name already exists.');

    expect(Classification::query()->count())->toBe(1);
});

it('lists classifications by name with the number of businesses using each one', function () {
    $retail = Classification::factory()->create(['name' => 'Retail']);
    Classification::factory()->create(['name' => 'Wholesale']);
    $company = Company::factory()->create(['classification_id' => $retail->id, 'name' => 'Harbor']);
    Company::factory()->create(['classification_id' => $retail->id, 'name' => 'Northwind']);
    $owner = classificationMember($company);

    $listed = $this->actingAs($owner)->getJson('/api/v1/classifications')->assertOk();
    $rows = collect($listed->json('data'))->keyBy('attributes.name');

    expect($rows->keys()->all())->toBe(['Retail', 'Wholesale'])
        ->and($rows['Retail']['attributes']['businesses_count'])->toBe(2)
        ->and($rows['Wholesale']['attributes']['businesses_count'])->toBe(0);
});

it('lets an owner rename a classification', function () {
    $company = Company::factory()->create();
    $owner = classificationMember($company);
    $classification = Classification::factory()->create(['name' => 'Retail']);

    $this->actingAs($owner)->patchJson("/api/v1/classifications/{$classification->id}", [
        'name' => '  Wholesale  ',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Wholesale');

    $this->assertDatabaseHas('classifications', [
        'id' => $classification->id,
        'name' => 'Wholesale',
    ]);
});

it('keeps the same classification name when an owner saves it again', function () {
    $company = Company::factory()->create();
    $owner = classificationMember($company);
    $classification = Classification::factory()->create(['name' => 'Retail']);

    $this->actingAs($owner)->patchJson("/api/v1/classifications/{$classification->id}", [
        'name' => 'retail',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'retail');
});

it('deletes a classification that no business uses', function () {
    $company = Company::factory()->create();
    $owner = classificationMember($company);
    $classification = Classification::factory()->create();

    $this->actingAs($owner)->deleteJson("/api/v1/classifications/{$classification->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('classifications', ['id' => $classification->id]);
});

it('refuses to delete a classification that a business still uses', function () {
    $classification = Classification::factory()->create(['name' => 'Retail']);
    $company = Company::factory()->create(['classification_id' => $classification->id]);
    $owner = classificationMember($company);

    $this->actingAs($owner)->deleteJson("/api/v1/classifications/{$classification->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.classification.0', 'Remove this classification from its businesses before deleting it.');

    $this->assertDatabaseHas('classifications', ['id' => $classification->id, 'name' => 'Retail']);
    expect($company->fresh()->classification_id)->toBe($classification->id);
});

it('refuses classification changes from someone who is not the owner', function (string $role) {
    $company = Company::factory()->create();
    $user = classificationMember($company, $role);
    $classification = Classification::factory()->create(['name' => 'Retail']);

    $this->actingAs($user)->postJson('/api/v1/classifications', [
        'name' => 'Wholesale',
    ])->assertForbidden();

    $this->actingAs($user)->patchJson("/api/v1/classifications/{$classification->id}", [
        'name' => 'Wholesale',
    ])->assertForbidden();

    $this->actingAs($user)->deleteJson("/api/v1/classifications/{$classification->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('classifications', ['id' => $classification->id, 'name' => 'Retail']);
    $this->assertDatabaseMissing('classifications', ['name' => 'Wholesale']);
})->with([
    CompanyRole::Accountant->value,
    CompanyRole::Staff->value,
]);

it('returns 401 for classifications when no one is signed in', function () {
    $this->getJson('/api/v1/classifications')->assertUnauthorized();
});

it('stores a category on a new classification', function () {
    $company = Company::factory()->create();
    $owner = classificationMember($company);
    $category = Category::factory()->create(['name' => 'Vegetables']);

    $created = $this->actingAs($owner)->postJson('/api/v1/classifications', [
        'name' => 'Grocery',
        'category_id' => $category->id,
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.category_id', (string) $category->id)
        ->assertJsonPath('data.attributes.category_name', 'Vegetables');

    $this->assertDatabaseHas('classifications', [
        'id' => (int) $created->json('data.id'),
        'category_id' => $category->id,
    ]);
});

it('lists each classification with its category', function () {
    $category = Category::factory()->create(['name' => 'Vegetables']);
    Classification::factory()->create([
        'name' => 'Grocery',
        'category_id' => $category->id,
    ]);
    $company = Company::factory()->create();
    $owner = classificationMember($company);

    $this->actingAs($owner)->getJson('/api/v1/classifications')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.category_id', (string) $category->id)
        ->assertJsonPath('data.0.attributes.category_name', 'Vegetables');
});

it('lets an owner change or clear a classification category', function () {
    $vegetables = Category::factory()->create(['name' => 'Vegetables']);
    $fisheries = Category::factory()->create(['name' => 'Live stocks and fisheries']);
    $classification = Classification::factory()->create([
        'name' => 'Grocery',
        'category_id' => $vegetables->id,
    ]);
    $company = Company::factory()->create();
    $owner = classificationMember($company);

    $this->actingAs($owner)->patchJson("/api/v1/classifications/{$classification->id}", [
        'name' => 'Grocery',
        'category_id' => $fisheries->id,
    ])->assertOk()
        ->assertJsonPath('data.attributes.category_name', 'Live stocks and fisheries');

    $this->actingAs($owner)->patchJson("/api/v1/classifications/{$classification->id}", [
        'name' => 'Grocery',
        'category_id' => null,
    ])->assertOk()
        ->assertJsonPath('data.attributes.category_id', null)
        ->assertJsonPath('data.attributes.category_name', null);

    expect($classification->fresh()->category_id)->toBeNull();
});

it('keeps the category when a classification edit omits it', function () {
    $category = Category::factory()->create(['name' => 'Vegetables']);
    $classification = Classification::factory()->create([
        'name' => 'Grocery',
        'category_id' => $category->id,
    ]);
    $company = Company::factory()->create();
    $owner = classificationMember($company);

    $this->actingAs($owner)->patchJson("/api/v1/classifications/{$classification->id}", [
        'name' => 'Market',
    ])->assertOk()
        ->assertJsonPath('data.attributes.category_id', (string) $category->id);

    expect($classification->fresh()->category_id)->toBe($category->id);
});

it('rejects a classification category that is not in the list', function () {
    $company = Company::factory()->create();
    $owner = classificationMember($company);
    $classification = Classification::factory()->create(['name' => 'Grocery']);

    $this->actingAs($owner)->patchJson("/api/v1/classifications/{$classification->id}", [
        'name' => 'Grocery',
        'category_id' => 9999,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.category_id.0', 'Choose a category from the list.');

    expect($classification->fresh()->category_id)->toBeNull();
});

it('stores a logo on a new classification and keeps it when the name changes', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $owner = classificationMember($company);

    $saved = $this->actingAs($owner)->post('/api/v1/classifications', [
        'name' => 'Grocery',
        'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
    ]);

    $saved->assertCreated();
    $classification = Classification::query()->findOrFail((int) $saved->json('data.id'));
    $path = $classification->logo_path;

    expect($path)->toBeString()
        ->and($saved->json('data.attributes.logo_url'))->toBe('/storage/'.$path);
    Storage::disk('public')->assertExists($path);

    $this->actingAs($owner)->patchJson("/api/v1/classifications/{$classification->id}", [
        'name' => 'Market',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Market')
        ->assertJsonPath('data.attributes.logo_url', '/storage/'.$path);

    Storage::disk('public')->assertExists($path);
});

it('lists a classification with its logo', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('logo.png', 64, 64)->store('classifications', 'public');
    Classification::factory()->create([
        'name' => 'Grocery',
        'logo_path' => $path,
    ]);
    $company = Company::factory()->create();
    $owner = classificationMember($company);

    $this->actingAs($owner)->getJson('/api/v1/classifications')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.logo_url', '/storage/'.$path);
});

it('replaces the classification logo and deletes the previous file', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $owner = classificationMember($company);
    $classification = Classification::factory()->create(['name' => 'Grocery']);

    $this->actingAs($owner)->post("/api/v1/classifications/{$classification->id}", [
        '_method' => 'PATCH',
        'name' => 'Grocery',
        'logo' => UploadedFile::fake()->image('first.png', 64, 64),
    ])->assertOk();

    $previous = $classification->fresh()->logo_path;

    $replaced = $this->actingAs($owner)->post("/api/v1/classifications/{$classification->id}", [
        '_method' => 'PATCH',
        'name' => 'Grocery',
        'logo' => UploadedFile::fake()->image('second.png', 64, 64),
    ]);

    $replaced->assertOk();
    $current = $classification->fresh()->logo_path;

    expect($current)->not->toBe($previous)
        ->and($replaced->json('data.attributes.logo_url'))->toBe('/storage/'.$current);
    Storage::disk('public')->assertMissing($previous);
    Storage::disk('public')->assertExists($current);
});

it('removes the classification logo when asked and ignores a submitted file path', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $owner = classificationMember($company);
    $classification = Classification::factory()->create(['name' => 'Grocery']);

    $this->actingAs($owner)->post("/api/v1/classifications/{$classification->id}", [
        '_method' => 'PATCH',
        'name' => 'Grocery',
        'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
    ])->assertOk();

    $path = $classification->fresh()->logo_path;

    $this->actingAs($owner)->post("/api/v1/classifications/{$classification->id}", [
        '_method' => 'PATCH',
        'name' => 'Grocery',
        'remove_logo' => '1',
        'logo_path' => 'classifications/evil.php',
    ])->assertOk()
        ->assertJsonPath('data.attributes.logo_url', null);

    expect($classification->fresh()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('deletes the classification logo when the classification is deleted', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('logo.png', 64, 64)->store('classifications', 'public');
    $classification = Classification::factory()->create([
        'name' => 'Grocery',
        'logo_path' => $path,
    ]);
    $company = Company::factory()->create();
    $owner = classificationMember($company);

    $this->actingAs($owner)->deleteJson("/api/v1/classifications/{$classification->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('classifications', ['id' => $classification->id]);
    Storage::disk('public')->assertMissing($path);
});

it('rejects a classification logo that is not a JPG, PNG, or WebP image', function (string $name, UploadedFile $logo) {
    Storage::fake('public');
    $company = Company::factory()->create();
    $owner = classificationMember($company);

    $this->actingAs($owner)->post('/api/v1/classifications', [
        'name' => 'Grocery',
        'logo' => $logo,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.logo.0', 'Use a JPG, PNG, or WebP logo.');

    $this->assertDatabaseMissing('classifications', ['name' => 'Grocery']);
})->with([
    'pdf' => ['pdf', UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')],
    'gif' => ['gif', UploadedFile::fake()->image('mark.gif', 32, 32)],
]);

it('rejects a classification logo larger than 2 MB', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $owner = classificationMember($company);

    $this->actingAs($owner)->post('/api/v1/classifications', [
        'name' => 'Grocery',
        'logo' => UploadedFile::fake()->image('logo.png')->size(2049),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.logo.0', 'The logo must be 2 MB or smaller.');

    $this->assertDatabaseMissing('classifications', ['name' => 'Grocery']);
});
