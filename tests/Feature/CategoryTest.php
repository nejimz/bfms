<?php

use App\Enums\CompanyRole;
use App\Models\Category;
use App\Models\Company;
use App\Models\User;

function categoryMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    return memberWithRole($company, $role);
}

it('creates a category and trims the name', function () {
    $company = Company::factory()->create();
    $owner = categoryMember($company);

    $created = $this->actingAs($owner)->postJson('/api/v1/categories', [
        'name' => '  Retail  ',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Retail')
        ->assertJsonPath('data.attributes.businesses_count', 0);

    $this->assertDatabaseHas('categories', [
        'id' => (int) $created->json('data.id'),
        'name' => 'Retail',
    ]);
});

it('rejects a category without a name or with a name over 255 characters', function () {
    $company = Company::factory()->create();
    $owner = categoryMember($company);

    $this->actingAs($owner)->postJson('/api/v1/categories', [
        'name' => '   ',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Enter a category name.');

    $this->actingAs($owner)->postJson('/api/v1/categories', [
        'name' => str_repeat('a', 256),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'The category name must be 255 characters or fewer.');
});

it('rejects a category name that already exists in any letter case', function () {
    $company = Company::factory()->create();
    $owner = categoryMember($company);
    Category::factory()->create(['name' => 'Retail']);

    $this->actingAs($owner)->postJson('/api/v1/categories', [
        'name' => 'retail',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'A category with this name already exists.');

    expect(Category::query()->count())->toBe(1);
});

it('lists categories by name with the number of businesses using each one', function () {
    $retail = Category::factory()->create(['name' => 'Retail']);
    Category::factory()->create(['name' => 'Wholesale']);
    $company = Company::factory()->create(['category_id' => $retail->id, 'name' => 'Harbor']);
    Company::factory()->create(['category_id' => $retail->id, 'name' => 'Northwind']);
    $owner = categoryMember($company);

    $listed = $this->actingAs($owner)->getJson('/api/v1/categories')->assertOk();
    $rows = collect($listed->json('data'))->keyBy('attributes.name');

    expect($rows->keys()->all())->toBe(['Retail', 'Wholesale'])
        ->and($rows['Retail']['attributes']['businesses_count'])->toBe(2)
        ->and($rows['Wholesale']['attributes']['businesses_count'])->toBe(0);
});

it('lets an owner rename a category', function () {
    $company = Company::factory()->create();
    $owner = categoryMember($company);
    $category = Category::factory()->create(['name' => 'Retail']);

    $this->actingAs($owner)->patchJson("/api/v1/categories/{$category->id}", [
        'name' => '  Wholesale  ',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Wholesale');

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Wholesale',
    ]);
});

it('keeps the same category name when an owner saves it again', function () {
    $company = Company::factory()->create();
    $owner = categoryMember($company);
    $category = Category::factory()->create(['name' => 'Retail']);

    $this->actingAs($owner)->patchJson("/api/v1/categories/{$category->id}", [
        'name' => 'retail',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'retail');
});

it('deletes a category that no business uses', function () {
    $company = Company::factory()->create();
    $owner = categoryMember($company);
    $category = Category::factory()->create();

    $this->actingAs($owner)->deleteJson("/api/v1/categories/{$category->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

it('refuses to delete a category that a business still uses', function () {
    $category = Category::factory()->create(['name' => 'Retail']);
    $company = Company::factory()->create(['category_id' => $category->id]);
    $owner = categoryMember($company);

    $this->actingAs($owner)->deleteJson("/api/v1/categories/{$category->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.category.0', 'Remove this category from its businesses before deleting it.');

    $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Retail']);
    expect($company->fresh()->category_id)->toBe($category->id);
});

it('refuses category changes from someone who is not the owner', function (string $role) {
    $company = Company::factory()->create();
    $user = categoryMember($company, $role);
    $category = Category::factory()->create(['name' => 'Retail']);

    $this->actingAs($user)->postJson('/api/v1/categories', [
        'name' => 'Wholesale',
    ])->assertForbidden();

    $this->actingAs($user)->patchJson("/api/v1/categories/{$category->id}", [
        'name' => 'Wholesale',
    ])->assertForbidden();

    $this->actingAs($user)->deleteJson("/api/v1/categories/{$category->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Retail']);
    $this->assertDatabaseMissing('categories', ['name' => 'Wholesale']);
})->with([
    CompanyRole::Accountant->value,
    CompanyRole::Staff->value,
]);

it('returns 401 for categories when no one is signed in', function () {
    $this->getJson('/api/v1/categories')->assertUnauthorized();
});
