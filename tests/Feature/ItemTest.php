<?php

use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Enums\CompanyRole;
use App\Models\ActivityLog;
use App\Models\ChartAccountEntryLine;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\User;

function itemMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    return memberWithRole($company, $role);
}

it('creates an item and trims the name, unit, and description', function () {
    $company = Company::factory()->create();
    $owner = itemMember($company);

    $created = $this->actingAs($owner)->postJson('/api/v1/items', [
        'name' => '  Rice  ',
        'unit' => '  kg  ',
        'description' => '  Fresh market rice  ',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Rice')
        ->assertJsonPath('data.attributes.unit', 'kg')
        ->assertJsonPath('data.attributes.description', 'Fresh market rice')
        ->assertJsonPath('data.attributes.entries_count', 0);

    $this->assertDatabaseHas('items', [
        'id' => (int) $created->json('data.id'),
        'name' => 'Rice',
        'unit' => 'kg',
        'description' => 'Fresh market rice',
    ]);
});

it('stores an item without a description', function () {
    $company = Company::factory()->create();
    $owner = itemMember($company);

    $created = $this->actingAs($owner)->postJson('/api/v1/items', [
        'name' => 'Sack',
        'unit' => 'piece',
        'description' => '   ',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.description', null);

    $this->assertDatabaseHas('items', [
        'id' => (int) $created->json('data.id'),
        'description' => null,
    ]);
});

it('rejects an item without a name or unit, or with a value that is too long', function () {
    $company = Company::factory()->create();
    $owner = itemMember($company);

    $this->actingAs($owner)->postJson('/api/v1/items', [
        'name' => '   ',
        'unit' => 'kg',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Enter an item name.');

    $this->actingAs($owner)->postJson('/api/v1/items', [
        'name' => 'Rice',
        'unit' => '   ',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.unit.0', 'Enter a unit.');

    $this->actingAs($owner)->postJson('/api/v1/items', [
        'name' => str_repeat('a', 256),
        'unit' => 'kg',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'The item name must be 255 characters or fewer.');

    $this->actingAs($owner)->postJson('/api/v1/items', [
        'name' => 'Rice',
        'unit' => str_repeat('a', 51),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.unit.0', 'The unit must be 50 characters or fewer.');

    $this->actingAs($owner)->postJson('/api/v1/items', [
        'name' => 'Rice',
        'unit' => 'kg',
        'description' => str_repeat('a', 1001),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.description.0', 'The description must be 1000 characters or fewer.');
});

it('rejects an item name that already exists in any letter case', function () {
    $company = Company::factory()->create();
    $owner = itemMember($company);
    Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);

    $this->actingAs($owner)->postJson('/api/v1/items', [
        'name' => 'rice',
        'unit' => 'sack',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'An item with this name already exists.');

    expect(Item::query()->count())->toBe(1);
});

it('lists items by name with how many entry lines use each one', function () {
    $rice = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg', 'description' => 'Fresh']);
    Item::factory()->create(['name' => 'Sugar', 'unit' => 'kg', 'description' => null]);
    ChartAccountEntryLine::factory()->create(['item_id' => $rice->id, 'name' => 'Rice']);
    ChartAccountEntryLine::factory()->create(['item_id' => $rice->id, 'name' => 'Rice']);
    $company = Company::factory()->create();
    $staff = itemMember($company, CompanyRole::Staff->value);

    $listed = $this->actingAs($staff)->getJson('/api/v1/items')->assertOk();
    $rows = collect($listed->json('data'))->keyBy('attributes.name');

    expect($rows->keys()->all())->toBe(['Rice', 'Sugar'])
        ->and($rows['Rice']['attributes']['entries_count'])->toBe(2)
        ->and($rows['Rice']['attributes']['unit'])->toBe('kg')
        ->and($rows['Sugar']['attributes']['entries_count'])->toBe(0)
        ->and($rows['Sugar']['attributes']['description'])->toBeNull();
});

it('lets an owner rename an item and change its unit and description', function () {
    $company = Company::factory()->create();
    $owner = itemMember($company);
    $item = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg', 'description' => null]);

    $this->actingAs($owner)->patchJson("/api/v1/items/{$item->id}", [
        'name' => '  Brown rice  ',
        'unit' => '  sack  ',
        'description' => '  Milled  ',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Brown rice')
        ->assertJsonPath('data.attributes.unit', 'sack')
        ->assertJsonPath('data.attributes.description', 'Milled');

    $this->assertDatabaseHas('items', [
        'id' => $item->id,
        'name' => 'Brown rice',
        'unit' => 'sack',
        'description' => 'Milled',
    ]);
});

it('keeps the same item name when an owner saves it again', function () {
    $company = Company::factory()->create();
    $owner = itemMember($company);
    $item = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);

    $this->actingAs($owner)->patchJson("/api/v1/items/{$item->id}", [
        'name' => 'rice',
        'unit' => 'kg',
        'description' => null,
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'rice');
});

it('deletes an item that no entry uses', function () {
    $company = Company::factory()->create();
    $owner = itemMember($company);
    $item = Item::factory()->create();

    $this->actingAs($owner)->deleteJson("/api/v1/items/{$item->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('items', ['id' => $item->id]);
});

it('refuses to delete an item that inventory still holds', function () {
    $item = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);
    $inventory = Inventory::factory()->create(['item_id' => $item->id, 'quantity' => '4']);
    $company = Company::factory()->create();
    $owner = itemMember($company);

    $this->actingAs($owner)->deleteJson("/api/v1/items/{$item->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.item.0', 'Remove this item from inventory before deleting it.');

    $this->assertDatabaseHas('items', ['id' => $item->id, 'name' => 'Rice']);
    expect($inventory->fresh()->item_id)->toBe($item->id);
});

it('refuses to delete an item that an entry line still uses', function () {
    $item = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);
    $line = ChartAccountEntryLine::factory()->create(['item_id' => $item->id, 'name' => 'Rice']);
    $company = Company::factory()->create();
    $owner = itemMember($company);

    $this->actingAs($owner)->deleteJson("/api/v1/items/{$item->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.item.0', 'Remove this item from its entries before deleting it.');

    $this->assertDatabaseHas('items', ['id' => $item->id, 'name' => 'Rice']);
    expect($line->fresh()->item_id)->toBe($item->id);
});

it('records who added, changed, and deleted an item', function () {
    $company = Company::factory()->create();
    $admin = itemMember($company);
    $admin->update(['name' => 'Ada Admin']);

    $created = $this->actingAs($admin)->postJson('/api/v1/items', [
        'name' => '  Rice  ',
        'unit' => 'kg',
        'description' => '',
    ])->assertCreated();

    $id = $created->json('data.id');

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->id,
        'actor_name' => 'Ada Admin',
        'module' => ActivityModule::Item->value,
        'action' => ActivityAction::Added->value,
        'record_name' => 'Rice',
    ]);

    $this->actingAs($admin)->patchJson("/api/v1/items/{$id}", [
        'name' => 'Brown rice',
        'unit' => 'sack',
        'description' => 'Milled',
    ])->assertOk();

    $this->actingAs($admin)->patchJson("/api/v1/items/{$id}", [
        'name' => 'Brown rice',
        'unit' => 'sack',
        'description' => 'Milled',
    ])->assertOk();

    expect(ActivityLog::query()->where('action', ActivityAction::Changed)->count())->toBe(1)
        ->and(ActivityLog::query()->where('action', ActivityAction::Changed)->first()?->changes)->toBe([
            ['field' => 'Name', 'from' => 'Rice', 'to' => 'Brown rice'],
            ['field' => 'Unit', 'from' => 'kg', 'to' => 'sack'],
            ['field' => 'Description', 'from' => 'None', 'to' => 'Milled'],
        ]);

    $this->actingAs($admin)->getJson('/api/v1/activity-logs?module=item')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.record_name', 'Brown rice')
        ->assertJsonPath('data.0.attributes.changes.0.field', 'Name');

    $this->actingAs($admin)->deleteJson("/api/v1/items/{$id}")->assertNoContent();

    $this->assertDatabaseHas('activity_logs', [
        'actor_name' => 'Ada Admin',
        'module' => ActivityModule::Item->value,
        'action' => ActivityAction::Deleted->value,
        'record_name' => 'Brown rice',
    ]);
});

it('does not record a log when an item name is missing', function () {
    $company = Company::factory()->create();
    $admin = itemMember($company);

    $this->actingAs($admin)->postJson('/api/v1/items', [
        'name' => '   ',
        'unit' => 'kg',
    ])->assertUnprocessable();

    expect(ActivityLog::query()->count())->toBe(0);
});

it('does not record a log when an item delete is refused', function () {
    $company = Company::factory()->create();
    $admin = itemMember($company);
    $item = Item::factory()->create(['name' => 'Rice']);
    ChartAccountEntryLine::factory()->create(['item_id' => $item->id]);

    $this->actingAs($admin)->deleteJson("/api/v1/items/{$item->id}")
        ->assertUnprocessable();

    expect(ActivityLog::query()->count())->toBe(0);
    $this->assertDatabaseHas('items', ['id' => $item->id]);
});

it('refuses item changes from someone who is not an admin', function () {
    $company = Company::factory()->create();
    $staff = itemMember($company, CompanyRole::Staff->value);
    $item = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);

    $this->actingAs($staff)->postJson('/api/v1/items', [
        'name' => 'Sugar',
        'unit' => 'kg',
    ])->assertForbidden();

    $this->actingAs($staff)->patchJson("/api/v1/items/{$item->id}", [
        'name' => 'Sugar',
        'unit' => 'kg',
    ])->assertForbidden();

    $this->actingAs($staff)->deleteJson("/api/v1/items/{$item->id}")
        ->assertForbidden();

    $this->actingAs($staff)->getJson('/api/v1/activity-logs?module=item')->assertForbidden();

    $this->assertDatabaseHas('items', ['id' => $item->id, 'name' => 'Rice']);
    $this->assertDatabaseMissing('items', ['name' => 'Sugar']);
});

it('returns 401 for items when no one is signed in', function () {
    $this->getJson('/api/v1/items')->assertUnauthorized();
});
