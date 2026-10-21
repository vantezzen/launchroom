<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

function verificationUrlFor(int $id, string $hash): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $id, 'hash' => $hash],
    );
}

test('email verification screen can be rendered', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('verification.notice'))->assertOk();
});

test('unverified users are redirected to the email verification prompt', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('appearance.edit'))
        ->assertRedirect(route('verification.notice'));
});

test('email can be verified', function (): void {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $response = $this->actingAs($user)->get(verificationUrlFor($user->id, sha1($user->email)));

    Event::assertDispatched(Verified::class);
    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function (): void {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $this->actingAs($user)->get(verificationUrlFor($user->id, sha1('wrong-email')));

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
});

test('email is not verified with invalid user id', function (): void {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $this->actingAs($user)->get(verificationUrlFor(123, sha1($user->email)));

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
});

test('verified user is redirected to dashboard from verification prompt', function (): void {
    $user = User::factory()->create();

    Event::fake();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    Event::assertNotDispatched(Verified::class);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('already verified user visiting verification link is redirected without firing event again', function (): void {
    $user = User::factory()->create();

    Event::fake();

    $this->actingAs($user)
        ->get(verificationUrlFor($user->id, sha1($user->email)))
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});
