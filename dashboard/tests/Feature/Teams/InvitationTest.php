<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
});

/**
 * Invite through the HTTP endpoint and capture the token from the e-mail.
 */
function inviteAndCaptureToken(object $test, User $inviter, string $email, TeamRole $role): string
{
    $test->actingAs($inviter)->withSession(passwordConfirmed())
        ->post(route('team.invitations.store'), ['email' => $email, 'role' => $role->value])
        ->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$token, $email): bool {
        $mail = $notification->toMail($notifiable);
        $token = basename((string) $mail->actionUrl);

        return $notifiable->routes['mail'] === $email;
    });

    return (string) $token;
}

test('admins invite by e-mail; the token is stored hashed and audited', function (): void {
    $token = inviteAndCaptureToken($this, $this->admin, 'new@example.com', TeamRole::Developer);
    $invitation = TeamInvitation::query()->sole();

    expect($token)->toStartWith('lr_inv_')
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->toArray())->not->toHaveKey('token_hash')
        ->and(AuditEvent::query()->where('action', AuditAction::MemberInvited->value)->sole()->meta)->toEqual(['email' => 'new@example.com', 'role' => 'developer']);
});

test('inviting requires a recently confirmed password', function (): void {
    $this->actingAs($this->admin)
        ->post(route('team.invitations.store'), ['email' => 'new@example.com', 'role' => 'viewer'])
        ->assertRedirect(route('password.confirm'));

    expect(TeamInvitation::query()->count())->toBe(0);
});

test('an inviter cannot grant a role above their own', function (): void {
    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('team.invitations.store'), ['email' => 'boss@example.com', 'role' => 'owner'])
        ->assertSessionHasErrors('role');

    expect(TeamInvitation::query()->count())->toBe(0);
});

test('developers and viewers cannot invite', function (TeamRole $role): void {
    $member = memberOf($this->team, $role);

    $this->actingAs($member)->withSession(passwordConfirmed())
        ->post(route('team.invitations.store'), ['email' => 'x@example.com', 'role' => 'viewer'])
        ->assertForbidden();
})->with([[TeamRole::Developer], [TeamRole::Viewer]]);

test('a new person creates their account through the invitation (invite-only registration)', function (): void {
    $token = inviteAndCaptureToken($this, $this->admin, 'new@example.com', TeamRole::Developer);
    auth()->logout();

    $this->get(route('invitations.show', $token))->assertOk();

    $this->post(route('invitations.accept', $token), [
        'name' => 'New Person',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'new@example.com')->sole();

    expect($user->roleIn($this->team))->toBe(TeamRole::Developer)
        ->and($user->current_team_id)->toBe($this->team->id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(TeamInvitation::query()->sole()->accepted_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

test('invitations are single use', function (): void {
    $token = inviteAndCaptureToken($this, $this->admin, 'new@example.com', TeamRole::Viewer);
    auth()->logout();

    $payload = ['name' => 'A', 'password' => 'password', 'password_confirmation' => 'password'];
    $this->post(route('invitations.accept', $token), $payload)->assertRedirect();
    auth()->logout();

    $this->get(route('invitations.show', $token))->assertNotFound();
    $this->post(route('invitations.accept', $token), $payload)->assertSessionHasErrors('token');
});

test('invitations expire', function (): void {
    $token = inviteAndCaptureToken($this, $this->admin, 'new@example.com', TeamRole::Viewer);
    auth()->logout();
    $this->travel(8)->days();

    $this->get(route('invitations.show', $token))->assertNotFound();
});

test('an existing user accepts while signed in, only with the invited e-mail', function (): void {
    $invitee = User::factory()->create(['email' => 'member@example.com']);
    $stranger = User::factory()->create();
    $token = inviteAndCaptureToken($this, $this->admin, 'member@example.com', TeamRole::Viewer);

    $this->actingAs($stranger)->post(route('invitations.accept', $token))->assertSessionHasErrors('token');
    expect($stranger->belongsToTeam($this->team))->toBeFalse();

    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));
    expect($invitee->fresh()?->roleIn($this->team))->toBe(TeamRole::Viewer);
});

test('guests cannot take over an existing account through an invitation', function (): void {
    User::factory()->create(['email' => 'member@example.com']);
    $token = inviteAndCaptureToken($this, $this->admin, 'member@example.com', TeamRole::Viewer);
    auth()->logout();

    $this->post(route('invitations.accept', $token), ['name' => 'X', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertSessionHasErrors('token');
    $this->assertGuest();
});

test('admins revoke invitations of their team only', function (): void {
    inviteAndCaptureToken($this, $this->admin, 'new@example.com', TeamRole::Viewer);
    $invitation = TeamInvitation::query()->sole();
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($outsider)->delete(route('team.invitations.destroy', $invitation->public_id))->assertNotFound();
    $this->actingAs($this->admin)->delete(route('team.invitations.destroy', $invitation->public_id))->assertRedirect();

    expect(TeamInvitation::query()->count())->toBe(0);
});

test('a new invitation for the same e-mail replaces the previous token', function (): void {
    $first = inviteAndCaptureToken($this, $this->admin, 'new@example.com', TeamRole::Viewer);
    Notification::fake();
    inviteAndCaptureToken($this, $this->admin, 'new@example.com', TeamRole::Developer);
    auth()->logout();

    $this->get(route('invitations.show', $first))->assertNotFound();
    expect(TeamInvitation::query()->count())->toBe(1);
});

test('existing members cannot be invited again', function (): void {
    $member = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('team.invitations.store'), ['email' => $member->email, 'role' => 'viewer'])
        ->assertSessionHasErrors('email');
    expect(Membership::query()->where('user_id', $member->id)->count())->toBe(1);
});

test('several people can be invited at once, members are skipped and named', function (): void {
    $member = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('team.invitations.store'), ['emails' => "a@example.com, B@example.com\n{$member->email}; a@example.com", 'role' => 'developer'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(TeamInvitation::query()->pluck('email')->sort()->values()->all())->toBe(['a@example.com', 'b@example.com'])
        ->and(TeamInvitation::query()->where('role', TeamRole::Developer->value)->count())->toBe(2);
    Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 2);
});

test('a malformed address in the list invites nobody', function (): void {
    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('team.invitations.store'), ['emails' => 'a@example.com, not-an-address', 'role' => 'viewer'])
        ->assertSessionHasErrors('emails');

    expect(TeamInvitation::query()->count())->toBe(0);
});

test('a list of only existing members is refused', function (): void {
    $member = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('team.invitations.store'), ['emails' => $member->email, 'role' => 'viewer'])
        ->assertSessionHasErrors('emails');
});
