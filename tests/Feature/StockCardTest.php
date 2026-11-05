<?php

use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Enums\ChartAccountEntryStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\ChartAccountEntry;
use App\Models\ChartAccountEntryLine;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Item;
use App\Models\User;

function stockCardAdmin(Company $company): User
{
    return memberWithRole($company);
}

function stockCardLine(
    Company $company,
    Item $item,
    string $code,
    string $date,
    string $quantity,
    ChartAccountEntryStatus $status = ChartAccountEntryStatus::Posted,
): void {
    $account = Account::factory()->create([
        'company_id' => $company->id,
        'code' => $code,
    ]);
    $entry = ChartAccountEntry::factory()->create([
        'company_id' => $company->id,
        'account_id' => $account->id,
        'date' => $date,
        'status' => $status,
    ]);
    ChartAccountEntryLine::factory()->create([
        'company_id' => $company->id,
        'chart_account_entry_id' => $entry->id,
        'item_id' => $item->id,
        'name' => $item->name,
        'quantity' => $quantity,
    ]);
}

it('builds a stock card from the starting quantity, purchases, sales, and adjustments', function () {
    $company = Company::factory()->create();
    $admin = stockCardAdmin($company);
    $rice = Item::factory()->create(['name' => 'Rice', 'unit' => 'sack']);
    $sugar = Item::factory()->create(['name' => 'Sugar', 'unit' => 'kg']);
    Inventory::factory()->create([
        'item_id' => $rice->id,
        'date' => '2026-01-01',
        'quantity' => '100',
        'note' => null,
    ]);
    Inventory::factory()->create([
        'item_id' => $sugar->id,
        'date' => '2026-01-03',
        'quantity' => '1',
        'note' => null,
    ]);
    stockCardLine($company, $rice, '5100', '2026-01-01', '20');
    stockCardLine($company, $rice, '4000', '2026-01-08', '5');
    InventoryAdjustment::factory()->create([
        'item_id' => $rice->id,
        'date' => '2026-01-10',
        'quantity' => '-2',
        'note' => 'Spoiled',
    ]);

    $this->actingAs($admin)->getJson('/api/v1/reports/stock-card')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.date', '2026-01-01')
        ->assertJsonPath('data.0.item_name', 'Rice')
        ->assertJsonPath('data.0.unit', 'sack')
        ->assertJsonPath('data.0.purchase_quantity', null)
        ->assertJsonPath('data.0.sold_quantity', null)
        ->assertJsonPath('data.0.adjust_quantity', '100')
        ->assertJsonPath('data.0.remaining_quantity', '100')
        ->assertJsonPath('data.0.source', 'opening')
        ->assertJsonPath('data.1.date', '2026-01-01')
        ->assertJsonPath('data.1.purchase_quantity', '20')
        ->assertJsonPath('data.1.sold_quantity', null)
        ->assertJsonPath('data.1.adjust_quantity', null)
        ->assertJsonPath('data.1.remaining_quantity', '120')
        ->assertJsonPath('data.1.source', 'purchase')
        ->assertJsonPath('data.2.date', '2026-01-08')
        ->assertJsonPath('data.2.sold_quantity', '5')
        ->assertJsonPath('data.2.remaining_quantity', '115')
        ->assertJsonPath('data.2.source', 'sale')
        ->assertJsonPath('data.3.date', '2026-01-10')
        ->assertJsonPath('data.3.adjust_quantity', '-2')
        ->assertJsonPath('data.3.remaining_quantity', '113')
        ->assertJsonPath('data.3.source', 'adjustment')
        ->assertJsonPath('data.4.date', '2026-01-03')
        ->assertJsonPath('data.4.item_name', 'Sugar')
        ->assertJsonPath('data.4.adjust_quantity', '1')
        ->assertJsonPath('data.4.remaining_quantity', '1')
        ->assertJsonPath('data.4.source', 'opening');

    $this->actingAs($admin)->getJson('/api/v1/reports/stock-card?item_id='.$rice->id)
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.item_name', 'Rice')
        ->assertJsonPath('data.3.remaining_quantity', '113');
});

it('keeps drafts, operation expenses, and the current business from hiding other stock', function () {
    $current = Company::factory()->create();
    $other = Company::factory()->create();
    $admin = stockCardAdmin($current);
    $item = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);
    Inventory::factory()->create([
        'item_id' => $item->id,
        'date' => '2026-01-01',
        'quantity' => '10',
        'note' => null,
    ]);
    stockCardLine($current, $item, '4000', '2026-01-02', '4', ChartAccountEntryStatus::Draft);
    stockCardLine($current, $item, '5000', '2026-01-03', '6');
    stockCardLine($other, $item, '4000', '2026-01-04', '3');

    $this->actingAs($admin)->getJson('/api/v1/reports/stock-card')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.date', '2026-01-04')
        ->assertJsonPath('data.1.sold_quantity', '3')
        ->assertJsonPath('data.1.remaining_quantity', '7');
});

it('records an adjustment and removes it from the stock card', function () {
    $company = Company::factory()->create();
    $admin = stockCardAdmin($company);
    $admin->update(['name' => 'Ada Admin']);
    $item = Item::factory()->create(['name' => 'Rice', 'unit' => 'kg']);

    $created = $this->actingAs($admin)->postJson('/api/v1/inventory-adjustments', [
        'item_id' => $item->id,
        'date' => '2026-02-01',
        'quantity' => ' -1.50 ',
        'note' => '  Count  ',
    ])->assertCreated()
        ->assertJsonPath('data.attributes.quantity', '-1.5')
        ->assertJsonPath('data.attributes.note', 'Count')
        ->assertJsonPath('data.attributes.date', '2026-02-01');

    $this->assertDatabaseHas('activity_logs', [
        'actor_name' => 'Ada Admin',
        'module' => ActivityModule::Inventory->value,
        'action' => ActivityAction::Added->value,
        'record_name' => 'Rice',
    ]);
    expect(ActivityLog::query()->where('action', ActivityAction::Added)->first()?->changes)->toBe([
        ['field' => 'Date', 'from' => 'None', 'to' => '2026-02-01'],
        ['field' => 'Adjustment', 'from' => 'None', 'to' => '-1.5'],
    ]);

    $this->actingAs($admin)->getJson('/api/v1/reports/stock-card')
        ->assertOk()
        ->assertJsonPath('data.0.adjust_quantity', '-1.5')
        ->assertJsonPath('data.0.remaining_quantity', '-1.5');

    $this->actingAs($admin)->deleteJson('/api/v1/inventory-adjustments/'.$created->json('data.id'))
        ->assertNoContent();

    $this->assertDatabaseMissing('inventory_adjustments', ['id' => $created->json('data.id')]);
    $this->assertDatabaseHas('activity_logs', [
        'module' => ActivityModule::Inventory->value,
        'action' => ActivityAction::Deleted->value,
        'record_name' => 'Rice',
    ]);
    $this->actingAs($admin)->getJson('/api/v1/reports/stock-card')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects an adjustment of zero or without an item', function () {
    $company = Company::factory()->create();
    $admin = stockCardAdmin($company);
    $item = Item::factory()->create();

    $this->actingAs($admin)->postJson('/api/v1/inventory-adjustments', [
        'date' => '2026-02-01',
        'quantity' => '1',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.item_id.0', 'Choose an item.');

    $this->actingAs($admin)->postJson('/api/v1/inventory-adjustments', [
        'item_id' => $item->id,
        'date' => '2026-02-01',
        'quantity' => '0',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'Enter a quantity other than zero, with up to 3 decimal places.');

    $this->actingAs($admin)->postJson('/api/v1/inventory-adjustments', [
        'item_id' => $item->id,
        'quantity' => '1',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.date.0', 'Enter the date.');

    expect(InventoryAdjustment::query()->count())->toBe(0)
        ->and(ActivityLog::query()->count())->toBe(0);
});

it('refuses an adjustment from someone who is not an admin', function () {
    $company = Company::factory()->create();
    $staff = memberWithRole($company, CompanyRole::Staff->value);
    $item = Item::factory()->create();
    $adjustment = InventoryAdjustment::factory()->create(['item_id' => $item->id, 'quantity' => '-1']);

    $this->actingAs($staff)->postJson('/api/v1/inventory-adjustments', [
        'item_id' => $item->id,
        'date' => '2026-02-01',
        'quantity' => '2',
    ])->assertForbidden();

    $this->actingAs($staff)->deleteJson("/api/v1/inventory-adjustments/{$adjustment->id}")
        ->assertForbidden();

    $this->actingAs($staff)->getJson('/api/v1/reports/stock-card')->assertOk();
    $this->assertDatabaseHas('inventory_adjustments', ['id' => $adjustment->id]);
});

it('returns 401 for the stock card when no one is signed in', function () {
    $this->getJson('/api/v1/reports/stock-card')->assertUnauthorized();
    $this->postJson('/api/v1/inventory-adjustments', [])->assertUnauthorized();
});
