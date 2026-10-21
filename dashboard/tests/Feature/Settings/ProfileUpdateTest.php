<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

test('profile page is displayed', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User')
        ->and($user->email)->toBe('test@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('changing the email needs the current password, re-verification and notifies the old address (L6)', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user)->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'attacker@example.com'])
        ->assertSessionHasErrors('current_password');
    $this->actingAs($user)->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'attacker@example.com', 'current_password' => 'wrong'])
        ->assertSessionHasErrors('current_password');
    expect($user->refresh()->email)->toBe('old@example.com');

    // Name-only edits don't need it.
    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Renamed', 'email' => 'OLD@example.com'])->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Renamed', 'email' => 'new@example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('new@example.com')
        ->and($user->email_verified_at)->toBeNull();
    Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'old@example.com');
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('the last owner of a team cannot delete their account (L2)', function (): void {
    $team = Team::factory()->create(['name' => 'Acme']);
    $owner = memberOf($team, TeamRole::Owner);

    $this->actingAs($owner)->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');
    expect($owner->fresh())->not->toBeNull()
        ->and(session('errors')->first('password'))->toContain('last owner of Acme');

    // With a second owner it works.
    memberOf($team, TeamRole::Owner);
    $this->actingAs($owner)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();
    expect($owner->fresh())->toBeNull();
});

test('the only instance owner cannot delete their account (L2)', function (): void {
    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');
    expect($owner->fresh())->not->toBeNull();

    User::factory()->instanceOwner()->create();
    $this->actingAs($owner)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();
    expect($owner->fresh())->toBeNull();
});
