<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;

it('returns 401 when no one is signed in', function () {
    $this->patchJson('/api/v1/user/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnauthorized();
});

it('changes the signed-in user password', function () {
    $user = User::factory()->create([
        'email' => 'sara@bfms.test',
        'password' => 'password',
    ]);

    $this->actingAs($user)->patchJson('/api/v1/user/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk()
        ->assertJsonPath('message', 'Your password has been changed.');

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('password', $user->fresh()->password))->toBeFalse();

    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->postJson('/session/login', [
        'email' => 'sara@bfms.test',
        'password' => 'new-password',
    ])->assertOk();
});

it('returns 422 when the current password is wrong', function () {
    $user = User::factory()->create(['password' => 'password']);

    $this->actingAs($user)->patchJson('/api/v1/user/password', [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('returns 422 when the new password is missing, short, or repeated', function () {
    $user = User::factory()->create(['password' => 'password']);

    $this->actingAs($user)->patchJson('/api/v1/user/password', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'Enter your current password.')
        ->assertJsonPath('errors.password.0', 'Enter a new password.');

    $this->actingAs($user)->patchJson('/api/v1/user/password', [
        'current_password' => 'password',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'The password must be at least 8 characters.');

    $this->actingAs($user)->patchJson('/api/v1/user/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'other-password',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'The password confirmation does not match.');

    $this->actingAs($user)->patchJson('/api/v1/user/password', [
        'current_password' => 'password',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'Choose a password that is different from the current one.');
});

it('returns 403 when the account is inactive', function () {
    $user = User::factory()->inactive()->create(['password' => 'password']);

    $this->actingAs($user)->patchJson('/api/v1/user/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertForbidden();
});
