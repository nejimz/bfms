<?php

use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;

function activityAdmin(): User
{
    Company::factory()->create();

    return User::factory()->admin()->create(['name' => 'Ada Admin']);
}

it('lists the newest logs for one module and keeps the name of who did it', function () {
    $admin = activityAdmin();

    $this->travelTo('2026-10-27 09:00:00');
    $this->actingAs($admin)->postJson('/api/v1/categories', [
        'name' => 'Vegetables',
    ])->assertCreated();

    $this->travelTo('2026-10-27 10:00:00');
    $this->actingAs($admin)->postJson('/api/v1/categories', [
        'name' => 'Market',
    ])->assertCreated();
    $this->actingAs($admin)->postJson('/api/v1/locations', [
        'name' => 'Cebu',
    ])->assertCreated();

    $admin->update(['name' => 'Ada Renamed']);

    $listed = $this->actingAs($admin)->getJson('/api/v1/activity-logs?module=category')
        ->assertOk();

    expect($listed->json('data'))->toHaveCount(2)
        ->and($listed->json('data.0.attributes.record_name'))->toBe('Market')
        ->and($listed->json('data.0.attributes.actor_name'))->toBe('Ada Admin')
        ->and($listed->json('data.0.attributes.action'))->toBe(ActivityAction::Added->value)
        ->and($listed->json('data.0.attributes.changes'))->toBe([])
        ->and($listed->json('data.0.attributes.created_at'))->toBe('2026-10-27T02:00:00.000000Z')
        ->and($listed->json('data.1.attributes.record_name'))->toBe('Vegetables')
        ->and($listed->json('data.1.attributes.created_at'))->toBe('2026-10-27T01:00:00.000000Z');

    $locations = $this->actingAs($admin)->getJson('/api/v1/activity-logs?module=location')
        ->assertOk();

    expect($locations->json('data'))->toHaveCount(1)
        ->and($locations->json('data.0.attributes.record_name'))->toBe('Cebu');
});

it('returns the latest 50 logs for a module', function () {
    $admin = activityAdmin();

    ActivityLog::factory()
        ->count(51)
        ->sequence(fn (Sequence $sequence): array => [
            'record_name' => 'Record '.$sequence->index,
            'created_at' => now()->addMinutes($sequence->index),
        ])
        ->create([
            'user_id' => $admin->id,
            'actor_name' => 'Ada Admin',
            'module' => ActivityModule::Category,
            'action' => ActivityAction::Added,
        ]);

    $listed = $this->actingAs($admin)->getJson('/api/v1/activity-logs?module=category')
        ->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('data.0.attributes.record_name', 'Record 50');

    $names = collect($listed->json('data'))->pluck('attributes.record_name');

    expect($names)->not->toContain('Record 0');
});

it('returns 401 when no one is signed in', function () {
    $this->getJson('/api/v1/activity-logs?module=category')->assertUnauthorized();
});

it('returns 403 when a manager lists logs', function () {
    Company::factory()->create();
    $manager = User::factory()->manager()->create();

    $this->actingAs($manager)->getJson('/api/v1/activity-logs?module=category')->assertForbidden();
});

it('returns 422 when the module is missing', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)->getJson('/api/v1/activity-logs')
        ->assertUnprocessable()
        ->assertJsonPath('errors.module.0', 'Choose a module.');
});

it('returns 422 when the module is unknown', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)->getJson('/api/v1/activity-logs?module=journals')
        ->assertUnprocessable()
        ->assertJsonPath('errors.module.0', 'Choose a module.');
});

it('records who added, changed, and deleted a category', function () {
    $admin = activityAdmin();

    $created = $this->actingAs($admin)->postJson('/api/v1/categories', [
        'name' => '  Vegetables  ',
    ])->assertCreated();

    $id = $created->json('data.id');

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->id,
        'actor_name' => 'Ada Admin',
        'module' => ActivityModule::Category->value,
        'action' => ActivityAction::Added->value,
        'record_name' => 'Vegetables',
    ]);

    $this->actingAs($admin)->patchJson("/api/v1/categories/{$id}", [
        'name' => 'Market',
    ])->assertOk();

    $this->actingAs($admin)->patchJson("/api/v1/categories/{$id}", [
        'name' => 'Market',
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'module' => ActivityModule::Category->value,
        'action' => ActivityAction::Changed->value,
        'record_name' => 'Market',
    ]);
    expect(ActivityLog::query()->where('action', ActivityAction::Changed)->first()?->changes)->toBe([
        ['field' => 'Name', 'from' => 'Vegetables', 'to' => 'Market'],
    ]);

    $this->actingAs($admin)->getJson('/api/v1/activity-logs?module=category')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.changes.0.field', 'Name')
        ->assertJsonPath('data.0.attributes.changes.0.from', 'Vegetables')
        ->assertJsonPath('data.0.attributes.changes.0.to', 'Market');

    $this->actingAs($admin)->deleteJson("/api/v1/categories/{$id}")->assertNoContent();

    $this->assertDatabaseHas('activity_logs', [
        'actor_name' => 'Ada Admin',
        'module' => ActivityModule::Category->value,
        'action' => ActivityAction::Deleted->value,
        'record_name' => 'Market',
    ]);
    $this->assertDatabaseMissing('categories', ['id' => $id]);
});

it('does not record a log when a category name is missing', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)->postJson('/api/v1/categories', [
        'name' => '   ',
    ])->assertUnprocessable();

    expect(ActivityLog::query()->count())->toBe(0);
});

it('does not record a log when a category delete is refused', function () {
    $admin = activityAdmin();
    $category = Category::factory()->create(['name' => 'Vegetables']);
    Classification::factory()->create(['category_id' => $category->id]);

    $this->actingAs($admin)->deleteJson("/api/v1/categories/{$category->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.category.0', 'Remove this category from its classifications before deleting it.');

    expect(ActivityLog::query()->count())->toBe(0);
    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

it('records a company profile change and ignores an unchanged save', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)->postJson('/api/v1/organization', [
        'name' => 'Northwind',
    ])->assertOk();

    $this->actingAs($admin)->postJson('/api/v1/organization', [
        'name' => 'Northwind',
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'actor_name' => 'Ada Admin',
        'module' => ActivityModule::Company->value,
        'action' => ActivityAction::Changed->value,
        'record_name' => 'Northwind',
    ]);
    expect(ActivityLog::query()->first()?->changes)->toBe([
        ['field' => 'Name', 'from' => 'BFMS', 'to' => 'Northwind'],
    ])
        ->and(ActivityLog::query()->count())->toBe(1);
});

it('records an added and changed business and ignores switching businesses', function () {
    $admin = activityAdmin();

    $created = $this->actingAs($admin)->postJson('/api/v1/companies', [
        'name' => 'Northwind',
        'description' => 'Wholesale',
        'currency' => 'USD',
    ])->assertCreated();

    $id = $created->json('data.id');

    $this->actingAs($admin)->patchJson("/api/v1/companies/{$id}", [
        'name' => 'Northwind',
        'description' => 'Wholesale',
        'currency' => 'USD',
    ])->assertOk();

    $this->actingAs($admin)->patchJson("/api/v1/companies/{$id}", [
        'name' => 'Harbor Goods',
        'description' => 'Retail',
        'currency' => 'USD',
    ])->assertOk();

    $this->actingAs($admin)->postJson('/api/v1/current-company', [
        'company_id' => $id,
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'module' => ActivityModule::Business->value,
        'action' => ActivityAction::Added->value,
        'record_name' => 'Northwind',
    ]);
    $this->assertDatabaseHas('activity_logs', [
        'module' => ActivityModule::Business->value,
        'action' => ActivityAction::Changed->value,
        'record_name' => 'Harbor Goods',
    ]);
    expect(ActivityLog::query()->where('action', ActivityAction::Changed)->first()?->changes)->toBe([
        ['field' => 'Name', 'from' => 'Northwind', 'to' => 'Harbor Goods'],
        ['field' => 'Description', 'from' => 'Wholesale', 'to' => 'Retail'],
    ])
        ->and(ActivityLog::query()->count())->toBe(2);
});

it('records who added, changed, and deleted a classification', function () {
    $admin = activityAdmin();

    $created = $this->actingAs($admin)->postJson('/api/v1/classifications', [
        'name' => 'Retail',
    ])->assertCreated();

    $id = $created->json('data.id');

    $this->actingAs($admin)->patchJson("/api/v1/classifications/{$id}", [
        'name' => 'Wholesale',
    ])->assertOk();

    $this->actingAs($admin)->patchJson("/api/v1/classifications/{$id}", [
        'name' => 'Wholesale',
    ])->assertOk();

    $this->actingAs($admin)->deleteJson("/api/v1/classifications/{$id}")->assertNoContent();

    $this->assertDatabaseHas('activity_logs', [
        'module' => ActivityModule::Classification->value,
        'action' => ActivityAction::Added->value,
        'record_name' => 'Retail',
    ]);
    $this->assertDatabaseHas('activity_logs', [
        'module' => ActivityModule::Classification->value,
        'action' => ActivityAction::Changed->value,
        'record_name' => 'Wholesale',
    ]);
    $this->assertDatabaseHas('activity_logs', [
        'module' => ActivityModule::Classification->value,
        'action' => ActivityAction::Deleted->value,
        'record_name' => 'Wholesale',
    ]);
    expect(ActivityLog::query()->where('action', ActivityAction::Changed)->first()?->changes)->toBe([
        ['field' => 'Name', 'from' => 'Retail', 'to' => 'Wholesale'],
    ]);
});

it('does not record a log when a classification delete is refused', function () {
    $classification = Classification::factory()->create(['name' => 'Retail']);
    Company::factory()->create(['classification_id' => $classification->id]);
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);

    $this->actingAs($admin)->deleteJson("/api/v1/classifications/{$classification->id}")
        ->assertUnprocessable();

    expect(ActivityLog::query()->count())->toBe(0);
    $this->assertDatabaseHas('classifications', ['id' => $classification->id]);
});

it('records who added, changed, and deleted a location', function () {
    $admin = activityAdmin();

    $created = $this->actingAs($admin)->postJson('/api/v1/locations', [
        'name' => 'Cebu',
    ])->assertCreated();

    $id = $created->json('data.id');

    $this->actingAs($admin)->patchJson("/api/v1/locations/{$id}", [
        'name' => 'Manila',
    ])->assertOk();

    $this->actingAs($admin)->patchJson("/api/v1/locations/{$id}", [
        'name' => 'Manila',
    ])->assertOk();

    $this->actingAs($admin)->deleteJson("/api/v1/locations/{$id}")->assertNoContent();

    $this->assertDatabaseHas('activity_logs', [
        'module' => ActivityModule::Location->value,
        'action' => ActivityAction::Added->value,
        'record_name' => 'Cebu',
    ]);
    $this->assertDatabaseHas('activity_logs', [
        'module' => ActivityModule::Location->value,
        'action' => ActivityAction::Changed->value,
        'record_name' => 'Manila',
    ]);
    $this->assertDatabaseHas('activity_logs', [
        'module' => ActivityModule::Location->value,
        'action' => ActivityAction::Deleted->value,
        'record_name' => 'Manila',
    ]);
    expect(ActivityLog::query()->where('action', ActivityAction::Changed)->first()?->changes)->toBe([
        ['field' => 'Name', 'from' => 'Cebu', 'to' => 'Manila'],
    ]);
});

it('does not record a log when a location delete is refused', function () {
    $location = Location::factory()->create(['name' => 'Cebu']);
    Company::factory()->create(['location_id' => $location->id]);
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);

    $this->actingAs($admin)->deleteJson("/api/v1/locations/{$location->id}")
        ->assertUnprocessable();

    expect(ActivityLog::query()->count())->toBe(0);
    $this->assertDatabaseHas('locations', ['id' => $location->id]);
});

it('records an added user and leaves the password out of the log', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Sara Staff',
        'email' => 'sara@bfms.test',
        'password' => 'secret-pass',
        'role' => UserRole::Manager->value,
    ])->assertCreated();

    $this->assertDatabaseHas('activity_logs', [
        'actor_name' => 'Ada Admin',
        'module' => ActivityModule::User->value,
        'action' => ActivityAction::Added->value,
        'record_name' => 'Sara Staff',
    ]);

    expect(ActivityLog::query()->pluck('record_name')->all())->not->toContain('secret-pass');
});

it('records a user change when assigned businesses change and skips an unchanged save', function () {
    $alpha = Company::factory()->create(['name' => 'Alpha']);
    $beta = Company::factory()->create(['name' => 'Beta']);
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $staff = User::factory()->create([
        'name' => 'Sara Staff',
        'email' => 'sara@bfms.test',
        'role' => UserRole::Staff,
    ]);
    $staff->companies()->attach($alpha->id, ['role' => CompanyRole::Staff->value]);

    $this->actingAs($admin)->patchJson("/api/v1/users/{$staff->id}", [
        'name' => 'Sara Staff',
        'email' => 'sara@bfms.test',
        'role' => UserRole::Staff->value,
        'company_ids' => [$alpha->id],
    ])->assertOk();

    expect(ActivityLog::query()->count())->toBe(0);

    $this->actingAs($admin)->patchJson("/api/v1/users/{$staff->id}", [
        'name' => 'Sara Staff',
        'email' => 'sara@bfms.test',
        'role' => UserRole::Staff->value,
        'company_ids' => [$beta->id],
    ])->assertOk();

    $this->actingAs($admin)->patchJson("/api/v1/users/{$staff->id}", [
        'name' => 'Sara Staff',
        'email' => 'sara@bfms.test',
        'role' => UserRole::Staff->value,
        'company_ids' => [$beta->id],
        'password' => 'another-secret',
    ])->assertOk();

    $logs = ActivityLog::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->changes)->toBe([
            ['field' => 'Businesses', 'from' => 'Alpha', 'to' => 'Beta'],
        ])
        ->and($logs[1]->changes)->toBe([
            ['field' => 'Password', 'from' => 'Hidden', 'to' => 'Updated'],
        ])
        ->and(json_encode($logs[1]->changes))->not->toContain('another-secret');
});

it('records a deactivation as a change and keeps the account', function () {
    $admin = activityAdmin();
    $other = User::factory()->admin()->create(['name' => 'Bea Admin']);

    $this->actingAs($admin)->deleteJson("/api/v1/users/{$other->id}")
        ->assertOk()
        ->assertJsonPath('data.attributes.is_active', false);

    $this->assertDatabaseHas('activity_logs', [
        'actor_name' => 'Ada Admin',
        'module' => ActivityModule::User->value,
        'action' => ActivityAction::Changed->value,
        'record_name' => 'Bea Admin',
    ]);
    expect(ActivityLog::query()->first()?->changes)->toBe([
        ['field' => 'Status', 'from' => 'Active', 'to' => 'Inactive'],
    ]);
    $this->assertDatabaseHas('users', [
        'id' => $other->id,
        'is_active' => false,
    ]);
});

it('does not record a log when you deactivate your own account', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)->deleteJson("/api/v1/users/{$admin->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.user.0', 'You cannot deactivate your own account.');

    expect(ActivityLog::query()->count())->toBe(0);
});

it('records a password change without storing the password', function () {
    $user = User::factory()->create([
        'name' => 'Sara Staff',
        'password' => 'password',
    ]);

    $this->actingAs($user)->patchJson('/api/v1/user/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->id,
        'actor_name' => 'Sara Staff',
        'module' => ActivityModule::User->value,
        'action' => ActivityAction::Changed->value,
        'record_name' => 'Sara Staff',
    ]);

    $log = ActivityLog::query()->first();

    expect($log?->changes)->toBe([
        ['field' => 'Password', 'from' => 'Hidden', 'to' => 'Updated'],
    ])
        ->and(json_encode($log?->changes))->not->toContain('new-password')
        ->and(ActivityLog::query()->pluck('record_name')->all())->not->toContain('new-password');
});

it('does not record a log when the current password is wrong', function () {
    $user = User::factory()->create(['password' => 'password']);

    $this->actingAs($user)->patchJson('/api/v1/user/password', [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnprocessable();

    expect(ActivityLog::query()->count())->toBe(0);
});
