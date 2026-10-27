<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;

function locationMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    return memberWithRole($company, $role);
}

it('creates a location and trims the name', function () {
    $company = Company::factory()->create();
    $owner = locationMember($company);

    $created = $this->actingAs($owner)->postJson('/api/v1/locations', [
        'name' => '  Cebu  ',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Cebu')
        ->assertJsonPath('data.attributes.businesses_count', 0);

    $this->assertDatabaseHas('locations', [
        'id' => (int) $created->json('data.id'),
        'name' => 'Cebu',
    ]);
});

it('rejects a location without a name or with a name over 255 characters', function () {
    $company = Company::factory()->create();
    $owner = locationMember($company);

    $this->actingAs($owner)->postJson('/api/v1/locations', [
        'name' => '   ',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Enter a location name.');

    $this->actingAs($owner)->postJson('/api/v1/locations', [
        'name' => str_repeat('a', 256),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'The location name must be 255 characters or fewer.');
});

it('rejects a location name that already exists in any letter case', function () {
    $company = Company::factory()->create();
    $owner = locationMember($company);
    Location::factory()->create(['name' => 'Cebu']);

    $this->actingAs($owner)->postJson('/api/v1/locations', [
        'name' => 'cebu',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'A location with this name already exists.');

    expect(Location::query()->count())->toBe(1);
});

it('lists locations by name with the number of businesses using each one', function () {
    $cebu = Location::factory()->create(['name' => 'Cebu']);
    Location::factory()->create(['name' => 'Manila']);
    $company = Company::factory()->create(['location_id' => $cebu->id, 'name' => 'Harbor']);
    Company::factory()->create(['location_id' => $cebu->id, 'name' => 'Northwind']);
    $owner = locationMember($company);

    $listed = $this->actingAs($owner)->getJson('/api/v1/locations')->assertOk();
    $rows = collect($listed->json('data'))->keyBy('attributes.name');

    expect($rows->keys()->all())->toBe(['Cebu', 'Manila'])
        ->and($rows['Cebu']['attributes']['businesses_count'])->toBe(2)
        ->and($rows['Manila']['attributes']['businesses_count'])->toBe(0);
});

it('lets an owner rename a location', function () {
    $company = Company::factory()->create();
    $owner = locationMember($company);
    $location = Location::factory()->create(['name' => 'Cebu']);

    $this->actingAs($owner)->patchJson("/api/v1/locations/{$location->id}", [
        'name' => '  Manila  ',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Manila');

    $this->assertDatabaseHas('locations', [
        'id' => $location->id,
        'name' => 'Manila',
    ]);
});

it('keeps the same location name when an owner saves it again', function () {
    $company = Company::factory()->create();
    $owner = locationMember($company);
    $location = Location::factory()->create(['name' => 'Cebu']);

    $this->actingAs($owner)->patchJson("/api/v1/locations/{$location->id}", [
        'name' => 'cebu',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'cebu');
});

it('deletes a location that no business uses', function () {
    $company = Company::factory()->create();
    $owner = locationMember($company);
    $location = Location::factory()->create();

    $this->actingAs($owner)->deleteJson("/api/v1/locations/{$location->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('locations', ['id' => $location->id]);
});

it('refuses to delete a location that a business still uses', function () {
    $location = Location::factory()->create(['name' => 'Cebu']);
    $company = Company::factory()->create(['location_id' => $location->id]);
    $owner = locationMember($company);

    $this->actingAs($owner)->deleteJson("/api/v1/locations/{$location->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.location.0', 'Remove this location from its businesses before deleting it.');

    $this->assertDatabaseHas('locations', ['id' => $location->id, 'name' => 'Cebu']);
    expect($company->fresh()->location_id)->toBe($location->id);
});

it('refuses location changes from someone who is not the owner', function (string $role) {
    $company = Company::factory()->create();
    $user = locationMember($company, $role);
    $location = Location::factory()->create(['name' => 'Cebu']);

    $this->actingAs($user)->postJson('/api/v1/locations', [
        'name' => 'Manila',
    ])->assertForbidden();

    $this->actingAs($user)->patchJson("/api/v1/locations/{$location->id}", [
        'name' => 'Manila',
    ])->assertForbidden();

    $this->actingAs($user)->deleteJson("/api/v1/locations/{$location->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('locations', ['id' => $location->id, 'name' => 'Cebu']);
    $this->assertDatabaseMissing('locations', ['name' => 'Manila']);
})->with([
    CompanyRole::Accountant->value,
    CompanyRole::Staff->value,
]);

it('returns 401 for locations when no one is signed in', function () {
    $this->getJson('/api/v1/locations')->assertUnauthorized();
});
