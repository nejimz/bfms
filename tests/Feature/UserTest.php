<?php

use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

it('returns 401 when no one is signed in', function () {
    $this->getJson('/api/v1/users')->assertUnauthorized();
});

it('returns 403 when a manager lists users', function () {
    Company::factory()->create();
    $manager = User::factory()->manager()->create();

    $this->actingAs($manager)->getJson('/api/v1/users')->assertForbidden();
});

it('creates a staff user for the selected businesses', function () {
    $alpha = Company::factory()->create(['name' => 'Alpha']);
    $beta = Company::factory()->create(['name' => 'Beta']);
    $admin = User::factory()->admin()->create();

    $created = $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => '  Sara Staff  ',
        'email' => ' Sara@BFMS.test ',
        'password' => 'password',
        'role' => UserRole::Staff->value,
        'company_ids' => [$alpha->id],
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Sara Staff')
        ->assertJsonPath('data.attributes.email', 'sara@bfms.test')
        ->assertJsonPath('data.attributes.role', UserRole::Staff->value)
        ->assertJsonPath('data.attributes.is_active', true)
        ->assertJsonPath('data.attributes.companies.0.name', 'Alpha')
        ->assertJsonMissingPath('data.attributes.password');

    $staffId = (int) $created->json('data.id');

    $this->assertDatabaseHas('company_user', [
        'user_id' => $staffId,
        'company_id' => $alpha->id,
        'role' => CompanyRole::Staff->value,
    ]);
    $this->assertDatabaseMissing('company_user', [
        'user_id' => $staffId,
        'company_id' => $beta->id,
    ]);

    $staff = User::query()->findOrFail($staffId);

    $this->actingAs($staff)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonCount(1, 'data.attributes.companies')
        ->assertJsonPath('data.attributes.companies.0.name', 'Alpha');

    $this->actingAs($staff)->getJson('/api/v1/companies')->assertForbidden();
    $this->actingAs($staff)->getJson('/api/v1/journals')->assertForbidden();

    $this->actingAs($staff)->postJson('/api/v1/current-company', [
        'company_id' => $beta->id,
    ])->assertForbidden();
});

it('returns 422 when staff are created without a business', function () {
    Company::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Sara Staff',
        'email' => 'sara@bfms.test',
        'password' => 'password',
        'role' => UserRole::Staff->value,
        'company_ids' => [],
    ])->assertUnprocessable()
        ->assertJsonPath('errors.company_ids.0', 'Choose at least one business.');
});

it('creates a manager who can open every business', function () {
    $alpha = Company::factory()->create(['name' => 'Alpha']);
    Company::factory()->create(['name' => 'Beta']);
    $admin = User::factory()->admin()->create();

    $created = $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Mina Manager',
        'email' => 'mina@bfms.test',
        'password' => 'password',
        'role' => UserRole::Manager->value,
        'company_ids' => [$alpha->id],
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.attributes.role', UserRole::Manager->value)
        ->assertJsonPath('data.attributes.companies', []);

    $manager = User::query()->findOrFail($created->json('data.id'));

    $this->assertDatabaseMissing('company_user', [
        'user_id' => $manager->id,
    ]);

    $this->actingAs($manager)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.companies.0.name', 'Alpha')
        ->assertJsonPath('data.attributes.companies.1.name', 'Beta');

    $this->actingAs($manager)->getJson('/api/v1/journals')->assertForbidden();
});

it('returns 422 when the email is already used', function () {
    Company::factory()->create();
    $admin = User::factory()->admin()->create(['email' => 'ada@bfms.test']);

    $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Ada Admin',
        'email' => 'ada@bfms.test',
        'password' => 'password',
        'role' => UserRole::Manager->value,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'That email address is already used.');
});

it('updates a staff user and keeps the password when it is blank', function () {
    $alpha = Company::factory()->create(['name' => 'Alpha']);
    $beta = Company::factory()->create(['name' => 'Beta']);
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->create([
        'name' => 'Sara Staff',
        'email' => 'sara@bfms.test',
        'password' => 'password',
    ]);
    $alpha->users()->attach($staff->id, ['role' => CompanyRole::Staff->value]);
    $password = $staff->password;

    $this->actingAs($admin)->patchJson("/api/v1/users/{$staff->id}", [
        'name' => 'Sara Santos',
        'email' => 'sara@bfms.test',
        'password' => '',
        'role' => UserRole::Staff->value,
        'company_ids' => [$beta->id],
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Sara Santos')
        ->assertJsonPath('data.attributes.companies.0.name', 'Beta');

    expect($staff->fresh()->password)->toBe($password);
    $this->assertDatabaseMissing('company_user', [
        'user_id' => $staff->id,
        'company_id' => $alpha->id,
    ]);

    $this->actingAs($admin)->patchJson("/api/v1/users/{$staff->id}", [
        'name' => 'Sara Santos',
        'email' => 'sara@bfms.test',
        'role' => UserRole::Manager->value,
    ])->assertOk()
        ->assertJsonPath('data.attributes.role', UserRole::Manager->value)
        ->assertJsonPath('data.attributes.companies', []);

    $this->assertDatabaseMissing('company_user', [
        'user_id' => $staff->id,
    ]);
});

it('returns 422 when the only admin is deactivated', function () {
    Company::factory()->create();
    $admin = User::factory()->admin()->create([
        'name' => 'Ada Admin',
        'email' => 'ada@bfms.test',
    ]);

    $this->actingAs($admin)->patchJson("/api/v1/users/{$admin->id}", [
        'name' => 'Ada Admin',
        'email' => 'ada@bfms.test',
        'role' => UserRole::Admin->value,
        'is_active' => false,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.is_active.0', 'You cannot deactivate your own account.')
        ->assertJsonPath('errors.role.0', 'Keep at least one active admin.');

    $this->actingAs($admin)->deleteJson("/api/v1/users/{$admin->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.user.0', 'You cannot deactivate your own account.');

    expect($admin->fresh()->is_active)->toBeTrue()
        ->and($admin->fresh()->role)->toBe(UserRole::Admin);
});

it('deactivates another admin and rejects an inactive login', function () {
    Company::factory()->create();
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create([
        'email' => 'other@bfms.test',
        'password' => 'password',
    ]);

    $this->actingAs($admin)->deleteJson("/api/v1/users/{$other->id}")
        ->assertOk()
        ->assertJsonPath('data.attributes.is_active', false);

    expect($other->fresh()->is_active)->toBeFalse();

    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->postJson('/session/login', [
        'email' => 'other@bfms.test',
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'This account is inactive.');
});
