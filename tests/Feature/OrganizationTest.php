<?php

use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

it('allows only an admin to change the company profile', function (UserRole $role, bool $allowed) {
    $user = match ($role) {
        UserRole::Admin => User::factory()->admin()->create(),
        UserRole::Manager => User::factory()->manager()->create(),
        default => User::factory()->create(),
    };

    expect(Gate::forUser($user)->allows('update', Organization::current()))->toBe($allowed);
})->with([
    'admin' => [UserRole::Admin, true],
    'manager' => [UserRole::Manager, false],
    'staff' => [UserRole::Staff, false],
]);

it('shows the default company name before anyone saves a profile', function () {
    $this->getJson('/api/v1/organization')
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'BFMS')
        ->assertJsonPath('data.attributes.logo_url', null)
        ->assertJsonPath('data.attributes.icon_url', null)
        ->assertJsonPath('data.attributes.banner_url', null);
});

it('returns 401 when a guest saves the company profile', function () {
    $this->postJson('/api/v1/organization', [
        'name' => 'Northwind',
    ])->assertUnauthorized();

    expect(Organization::query()->where('name', 'Northwind')->exists())->toBeFalse();
});

it('returns 403 when a staff member saves the company profile', function () {
    $company = Company::factory()->create();
    $staff = memberWithRole($company, CompanyRole::Staff->value);

    $this->actingAs($staff)->postJson('/api/v1/organization', [
        'name' => 'Northwind',
    ])->assertForbidden();

    expect(Organization::current()->name)->toBe('BFMS');
});

it('saves a trimmed company name', function () {
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $this->actingAs($admin)->postJson('/api/v1/organization', [
        'name' => '  Northwind  ',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Northwind')
        ->assertJsonPath('data.attributes.logo_url', null)
        ->assertJsonPath('data.attributes.icon_url', null)
        ->assertJsonPath('data.attributes.banner_url', null);

    $this->assertDatabaseHas('organizations', [
        'id' => 1,
        'name' => 'Northwind',
        'logo_path' => null,
        'icon_path' => null,
        'banner_path' => null,
    ]);
});

it('rejects a blank company name or a name over 255 characters', function () {
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $this->actingAs($admin)->postJson('/api/v1/organization', [
        'name' => '   ',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Enter a company name.');

    $this->actingAs($admin)->postJson('/api/v1/organization', [
        'name' => str_repeat('a', 256),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'The company name must be 255 characters or fewer.');

    expect(Organization::current()->name)->toBe('BFMS');
});

it('stores a logo and keeps it when only the name changes', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $saved = $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
    ]);

    $saved->assertOk()
        ->assertJsonPath('data.attributes.name', 'Northwind');

    $path = Organization::current()->logo_path;
    expect($path)->toBeString()->and($saved->json('data.attributes.logo_url'))->toBe('/storage/'.$path);
    Storage::disk('public')->assertExists($path);

    $this->actingAs($admin)->postJson('/api/v1/organization', [
        'name' => 'Harbor',
    ])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Harbor')
        ->assertJsonPath('data.attributes.logo_url', '/storage/'.$path);

    Storage::disk('public')->assertExists($path);
});

it('replaces the logo and deletes the previous file', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'logo' => UploadedFile::fake()->image('first.png', 64, 64),
    ])->assertOk();

    $previous = Organization::current()->logo_path;

    $replaced = $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'logo' => UploadedFile::fake()->image('second.png', 64, 64),
    ]);

    $replaced->assertOk();
    $current = Organization::current()->logo_path;

    expect($current)->not->toBe($previous)
        ->and($replaced->json('data.attributes.logo_url'))->toBe('/storage/'.$current);
    Storage::disk('public')->assertMissing($previous);
    Storage::disk('public')->assertExists($current);
});

it('removes the logo when asked and ignores a submitted file path', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
    ])->assertOk();

    $path = Organization::current()->logo_path;

    $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'remove_logo' => '1',
        'logo_path' => 'organization/evil.php',
    ])->assertOk()
        ->assertJsonPath('data.attributes.logo_url', null);

    expect(Organization::current()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('rejects a logo that is not a JPG, PNG, or WebP image', function (string $name, UploadedFile $logo) {
    Storage::fake('public');
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'logo' => $logo,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.logo.0', 'Use a JPG, PNG, or WebP logo.');

    expect(Organization::current()->logo_path)->toBeNull();
})->with([
    'pdf' => ['pdf', UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')],
    'gif' => ['gif', UploadedFile::fake()->image('mark.gif', 32, 32)],
]);

it('rejects a logo larger than 2 MB', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'logo' => UploadedFile::fake()->image('logo.png')->size(2049),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.logo.0', 'The logo must be 2 MB or smaller.');

    expect(Organization::current()->logo_path)->toBeNull();
});

it('stores an icon and a banner without replacing the logo', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
    ])->assertOk();

    $logoPath = Organization::current()->logo_path;

    $saved = $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'icon' => UploadedFile::fake()->image('icon.png', 32, 32),
        'banner' => UploadedFile::fake()->image('banner.png', 640, 200),
    ]);

    $saved->assertOk();
    $organization = Organization::current();

    expect($organization->logo_path)->toBe($logoPath)
        ->and($organization->icon_path)->toBeString()
        ->and($organization->banner_path)->toBeString()
        ->and($organization->icon_path)->not->toBe($organization->banner_path)
        ->and($saved->json('data.attributes.icon_url'))->toBe('/storage/'.$organization->icon_path)
        ->and($saved->json('data.attributes.banner_url'))->toBe('/storage/'.$organization->banner_path)
        ->and($saved->json('data.attributes.logo_url'))->toBe('/storage/'.$logoPath);
    Storage::disk('public')->assertExists($logoPath);
    Storage::disk('public')->assertExists($organization->icon_path);
    Storage::disk('public')->assertExists($organization->banner_path);
});

it('removes the icon and leaves the banner in place', function () {
    Storage::fake('public');
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'icon' => UploadedFile::fake()->image('icon.png', 32, 32),
        'banner' => UploadedFile::fake()->image('banner.png', 640, 200),
    ])->assertOk();

    $bannerPath = Organization::current()->banner_path;
    $iconPath = Organization::current()->icon_path;

    $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        'remove_icon' => '1',
        'icon_path' => 'organization/evil.php',
    ])->assertOk()
        ->assertJsonPath('data.attributes.icon_url', null)
        ->assertJsonPath('data.attributes.banner_url', '/storage/'.$bannerPath);

    expect(Organization::current()->icon_path)->toBeNull()
        ->and(Organization::current()->banner_path)->toBe($bannerPath);
    Storage::disk('public')->assertMissing($iconPath);
    Storage::disk('public')->assertExists($bannerPath);
});

it('rejects an icon or banner that is not a JPG, PNG, or WebP image', function (string $field, string $message) {
    Storage::fake('public');
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        $field => UploadedFile::fake()->create($field.'.pdf', 20, 'application/pdf'),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.'.$field.'.0', $message);

    expect(Organization::current()->icon_path)->toBeNull()
        ->and(Organization::current()->banner_path)->toBeNull();
})->with([
    'icon' => ['icon', 'Use a JPG, PNG, or WebP icon.'],
    'banner' => ['banner', 'Use a JPG, PNG, or WebP banner.'],
]);

it('rejects an icon or banner larger than 2 MB', function (string $field, string $message) {
    Storage::fake('public');
    $company = Company::factory()->create();
    $admin = memberWithRole($company);

    $this->actingAs($admin)->post('/api/v1/organization', [
        'name' => 'Northwind',
        $field => UploadedFile::fake()->image($field.'.png')->size(2049),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.'.$field.'.0', $message);

    expect(Organization::current()->icon_path)->toBeNull()
        ->and(Organization::current()->banner_path)->toBeNull();
})->with([
    'icon' => ['icon', 'The icon must be 2 MB or smaller.'],
    'banner' => ['banner', 'The banner must be 2 MB or smaller.'],
]);
