<?php

use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Enums\CompanyRole;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\User;

function inventoryMember(Company $company, string $role = CompanyRole::Owner->value): User
{
    return memberWithRole($company, $role);
}

it('creates inventory and trims the quantity and note', function () {
    $company = Company::factory()->create();
    $owner = inventoryMember($company);
    $item = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);

    $created = $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'item_id' => $item->id,
        'quantity' => '  12.50  ',
        'note' => '  Cold room  ',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.item_id', (string) $item->id)
        ->assertJsonPath('data.attributes.item_name', 'Rice')
        ->assertJsonPath('data.attributes.unit', 'kg')
        ->assertJsonPath('data.attributes.quantity', '12.5')
        ->assertJsonPath('data.attributes.note', 'Cold room');

    $this->assertDatabaseHas('inventories', [
        'id' => (int) $created->json('data.id'),
        'item_id' => $item->id,
        'quantity' => '12.500',
        'note' => 'Cold room',
    ]);
});

it('stores inventory without a note and accepts a leading decimal', function () {
    $company = Company::factory()->create();
    $owner = inventoryMember($company);
    $item = Item::factory()->create(['name' => 'Sack', 'unit' => 'piece']);

    $created = $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'item_id' => $item->id,
        'quantity' => '.5',
        'note' => '   ',
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.quantity', '0.5')
        ->assertJsonPath('data.attributes.note', null);

    $this->assertDatabaseHas('inventories', [
        'id' => (int) $created->json('data.id'),
        'quantity' => '0.500',
        'note' => null,
    ]);
});

it('stores a quantity of zero', function () {
    $company = Company::factory()->create();
    $owner = inventoryMember($company);
    $item = Item::factory()->create();

    $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'item_id' => $item->id,
        'quantity' => '0',
    ])->assertCreated()
        ->assertJsonPath('data.attributes.quantity', '0');
});

it('rejects inventory without an item, with a bad quantity, or with a note that is too long', function () {
    $company = Company::factory()->create();
    $owner = inventoryMember($company);
    $item = Item::factory()->create();

    $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'quantity' => '1',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.item_id.0', 'Choose an item.');

    $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'item_id' => $item->id + 1000,
        'quantity' => '1',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.item_id.0', 'Choose an item.');

    $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'item_id' => $item->id,
        'quantity' => '   ',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'Enter a quantity of zero or more, with up to 3 decimal places.');

    $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'item_id' => $item->id,
        'quantity' => '-1',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'Enter a quantity of zero or more, with up to 3 decimal places.');

    $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'item_id' => $item->id,
        'quantity' => '1.2345',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'Enter a quantity of zero or more, with up to 3 decimal places.');

    $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'item_id' => $item->id,
        'quantity' => '1000000000',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'Enter a quantity of at most 999,999,999.999.');

    $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'item_id' => $item->id,
        'quantity' => '1',
        'note' => str_repeat('a', 1001),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.note.0', 'The note must be 1000 characters or fewer.');

    expect(Inventory::query()->count())->toBe(0);
});

it('rejects a second inventory row for the same item', function () {
    $company = Company::factory()->create();
    $owner = inventoryMember($company);
    $item = Item::factory()->create(['name' => 'Rice']);
    Inventory::factory()->create(['item_id' => $item->id, 'quantity' => '4']);

    $this->actingAs($owner)->postJson('/api/v1/inventories', [
        'item_id' => $item->id,
        'quantity' => '8',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.item_id.0', 'This item already has inventory.');

    expect(Inventory::query()->count())->toBe(1);
});

it('lists inventory by item name', function () {
    $sugar = Item::factory()->create(['name' => 'Sugar', 'unit' => 'kg']);
    $rice = Item::factory()->create(['name' => 'Rice', 'unit' => 'sack']);
    Inventory::factory()->create(['item_id' => $sugar->id, 'quantity' => '2', 'note' => null]);
    Inventory::factory()->create(['item_id' => $rice->id, 'quantity' => '10.5', 'note' => 'Sacks']);
    $company = Company::factory()->create();
    $staff = inventoryMember($company, CompanyRole::Staff->value);

    $listed = $this->actingAs($staff)->getJson('/api/v1/inventories')->assertOk();
    $rows = collect($listed->json('data'))->keyBy('attributes.item_name');

    expect($rows->keys()->all())->toBe(['Rice', 'Sugar'])
        ->and($rows['Rice']['attributes']['quantity'])->toBe('10.5')
        ->and($rows['Rice']['attributes']['unit'])->toBe('sack')
        ->and($rows['Rice']['attributes']['note'])->toBe('Sacks')
        ->and($rows['Sugar']['attributes']['quantity'])->toBe('2')
        ->and($rows['Sugar']['attributes']['note'])->toBeNull();
});

it('lets an owner change the item, quantity, and note', function () {
    $company = Company::factory()->create();
    $owner = inventoryMember($company);
    $rice = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);
    $sugar = Item::factory()->create(['name' => 'Sugar', 'unit' => 'kg']);
    $inventory = Inventory::factory()->create([
        'item_id' => $rice->id,
        'quantity' => '10',
        'note' => null,
    ]);

    $this->actingAs($owner)->patchJson("/api/v1/inventories/{$inventory->id}", [
        'item_id' => $sugar->id,
        'quantity' => '  3  ',
        'note' => '  Dry store  ',
    ])->assertOk()
        ->assertJsonPath('data.attributes.item_name', 'Sugar')
        ->assertJsonPath('data.attributes.quantity', '3')
        ->assertJsonPath('data.attributes.note', 'Dry store');

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'item_id' => $sugar->id,
        'quantity' => '3.000',
        'note' => 'Dry store',
    ]);
});

it('keeps the same item when an owner saves that inventory again', function () {
    $company = Company::factory()->create();
    $owner = inventoryMember($company);
    $item = Item::factory()->create(['name' => 'Rice']);
    $inventory = Inventory::factory()->create(['item_id' => $item->id, 'quantity' => '4', 'note' => null]);

    $this->actingAs($owner)->patchJson("/api/v1/inventories/{$inventory->id}", [
        'item_id' => $item->id,
        'quantity' => '4',
        'note' => null,
    ])->assertOk()
        ->assertJsonPath('data.attributes.item_id', (string) $item->id);
});

it('refuses to move inventory onto an item that already has a row', function () {
    $company = Company::factory()->create();
    $owner = inventoryMember($company);
    $rice = Item::factory()->create(['name' => 'Rice']);
    $sugar = Item::factory()->create(['name' => 'Sugar']);
    $inventory = Inventory::factory()->create(['item_id' => $rice->id, 'quantity' => '1']);
    Inventory::factory()->create(['item_id' => $sugar->id, 'quantity' => '2']);

    $this->actingAs($owner)->patchJson("/api/v1/inventories/{$inventory->id}", [
        'item_id' => $sugar->id,
        'quantity' => '1',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.item_id.0', 'This item already has inventory.');

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'item_id' => $rice->id,
    ]);
});

it('deletes inventory and leaves the item in place', function () {
    $company = Company::factory()->create();
    $owner = inventoryMember($company);
    $item = Item::factory()->create(['name' => 'Rice']);
    $inventory = Inventory::factory()->create(['item_id' => $item->id]);

    $this->actingAs($owner)->deleteJson("/api/v1/inventories/{$inventory->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('inventories', ['id' => $inventory->id]);
    $this->assertDatabaseHas('items', ['id' => $item->id, 'name' => 'Rice']);
});

it('records who added, changed, and deleted inventory', function () {
    $company = Company::factory()->create();
    $admin = inventoryMember($company);
    $admin->update(['name' => 'Ada Admin']);
    $rice = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);
    $sugar = Item::factory()->create(['name' => 'Sugar', 'unit' => 'kg']);

    $created = $this->actingAs($admin)->postJson('/api/v1/inventories', [
        'item_id' => $rice->id,
        'quantity' => '10',
        'note' => '',
    ])->assertCreated();

    $id = $created->json('data.id');

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->id,
        'actor_name' => 'Ada Admin',
        'module' => ActivityModule::Inventory->value,
        'action' => ActivityAction::Added->value,
        'record_name' => 'Rice',
    ]);

    $this->actingAs($admin)->patchJson("/api/v1/inventories/{$id}", [
        'item_id' => $sugar->id,
        'quantity' => '12.5',
        'note' => 'Dry store',
    ])->assertOk();

    $this->actingAs($admin)->patchJson("/api/v1/inventories/{$id}", [
        'item_id' => $sugar->id,
        'quantity' => '12.5',
        'note' => 'Dry store',
    ])->assertOk();

    expect(ActivityLog::query()->where('action', ActivityAction::Changed)->count())->toBe(1)
        ->and(ActivityLog::query()->where('action', ActivityAction::Changed)->first()?->changes)->toBe([
            ['field' => 'Item', 'from' => 'Rice', 'to' => 'Sugar'],
            ['field' => 'Quantity', 'from' => '10', 'to' => '12.5'],
            ['field' => 'Note', 'from' => 'None', 'to' => 'Dry store'],
        ]);

    $this->actingAs($admin)->getJson('/api/v1/activity-logs?module=inventory')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.record_name', 'Sugar')
        ->assertJsonPath('data.0.attributes.changes.0.field', 'Item');

    $this->actingAs($admin)->deleteJson("/api/v1/inventories/{$id}")->assertNoContent();

    $this->assertDatabaseHas('activity_logs', [
        'actor_name' => 'Ada Admin',
        'module' => ActivityModule::Inventory->value,
        'action' => ActivityAction::Deleted->value,
        'record_name' => 'Sugar',
    ]);
});

it('does not record a log when inventory quantity is missing', function () {
    $company = Company::factory()->create();
    $admin = inventoryMember($company);
    $item = Item::factory()->create();

    $this->actingAs($admin)->postJson('/api/v1/inventories', [
        'item_id' => $item->id,
        'quantity' => '   ',
    ])->assertUnprocessable();

    expect(ActivityLog::query()->count())->toBe(0);
});

it('refuses inventory changes from someone who is not an admin', function () {
    $company = Company::factory()->create();
    $staff = inventoryMember($company, CompanyRole::Staff->value);
    $item = Item::factory()->create(['name' => 'Rice']);
    $inventory = Inventory::factory()->create(['item_id' => $item->id, 'quantity' => '4']);

    $this->actingAs($staff)->postJson('/api/v1/inventories', [
        'item_id' => Item::factory()->create()->id,
        'quantity' => '1',
    ])->assertForbidden();

    $this->actingAs($staff)->patchJson("/api/v1/inventories/{$inventory->id}", [
        'item_id' => $item->id,
        'quantity' => '9',
    ])->assertForbidden();

    $this->actingAs($staff)->deleteJson("/api/v1/inventories/{$inventory->id}")
        ->assertForbidden();

    $this->actingAs($staff)->getJson('/api/v1/activity-logs?module=inventory')->assertForbidden();

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => '4.000',
    ]);
});

it('returns 401 for inventory when no one is signed in', function () {
    $this->getJson('/api/v1/inventories')->assertUnauthorized();
});
