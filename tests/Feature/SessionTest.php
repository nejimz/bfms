<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

function keepResponseCookies(TestResponse $response): void
{
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

it('returns 401 when no one is signed in', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->postJson('/session/logout')->assertUnauthorized();
});

it('ends the signed-in session', function () {
    Company::factory()->create();
    $user = User::factory()->admin()->create([
        'email' => 'owner@bfms.test',
    ]);

    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withCredentials();
    $this->withHeader('Origin', 'http://localhost');

    $login = $this->postJson('/session/login', [
        'email' => 'owner@bfms.test',
        'password' => 'password',
    ]);

    $login->assertOk()->assertJson(['ok' => true]);
    keepResponseCookies($login);

    $remember = collect($login->headers->getCookies())->first(
        fn (Cookie $cookie) => str_starts_with($cookie->getName(), 'remember_web_'),
    );

    expect($remember)->toBeInstanceOf(Cookie::class);

    $tokenWhileSignedIn = $user->fresh()->remember_token;

    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.email', 'owner@bfms.test');

    $logout = $this->postJson('/session/logout');

    $logout->assertOk()
        ->assertJson(['ok' => true])
        ->assertCookieExpired($remember->getName());

    expect($user->fresh()->remember_token)->not->toBe($tokenWhileSignedIn);

    $this->flushSession();
    Auth::forgetGuards();
    $this->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
    $this->withUnencryptedCookie($remember->getName(), $remember->getValue());

    $this->getJson('/api/v1/user')->assertUnauthorized();
});
