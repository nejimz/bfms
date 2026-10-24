<?php

use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function memberWithRole(Company $company, string $role = CompanyRole::Owner->value): User
{
    $platform = match ($role) {
        CompanyRole::Owner->value => UserRole::Admin,
        CompanyRole::Accountant->value => UserRole::Manager,
        default => UserRole::Staff,
    };

    $user = User::factory()->create([
        'role' => $platform,
    ]);

    if ($platform === UserRole::Staff) {
        $company->users()->attach($user->id, ['role' => CompanyRole::Staff->value]);
    }

    return $user;
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
