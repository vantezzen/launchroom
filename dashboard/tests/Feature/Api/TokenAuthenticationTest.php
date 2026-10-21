<?php

declare(strict_types=1);

use App\Actions\Teams\RemoveMember;
use App\Data\Audit\AuditActor;
use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Team;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('requests without a token are 401 in the API error format', function (): void {
    Api::get('projects', null)
        ->assertUnauthorized()
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Sign in again: this token is missing, expired or revoked.']]);
});

test('a dashboard session does not authenticate the API', function (): void {
    $world = DeploymentUi::make(false);

    $this->actingAs($world->member(TeamRole::Owner))
        ->getJson('/api/v1/projects')
        ->assertUnauthorized();
});

test('tokens are stored hashed, prefixed lr_pat_ and record last use and IP', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    $plain = Api::token($owner, $world->team);

    [$id, $secret] = explode('|', $plain, 2);
    $token = ApiToken::query()->findOrFail((int) $id);

    expect($secret)->toStartWith('lr_pat_')
        ->and($token->token)->toBe(hash('sha256', $secret))
        ->and($token->toArray())->not->toHaveKey('token')
        ->and($token->last_used_at)->toBeNull();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23']);
    Api::get('me', $plain)->assertOk()->assertJsonPath('data.team.id', $world->team->public_id);

    $token->refresh();
    expect($token->last_used_at)->not->toBeNull()
        ->and($token->last_used_ip)->toBe('198.51.100.23');
});

test('expired tokens and tokens without an expiry are refused (fail closed)', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);

    Api::get('me', Api::rawToken($owner, $world->team, ['read'], now()->subMinute()))->assertUnauthorized();
    Api::get('me', Api::rawToken($owner, $world->team, ['read'], null))->assertUnauthorized();
    Api::get('me', Api::rawToken($owner, $world->team, ['read']))->assertOk();
});

test('a wrong secret for an existing token id is refused', function (): void {
    $world = DeploymentUi::make(false);
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);
    [$id] = explode('|', $plain, 2);

    Api::get('me', $id.'|lr_pat_'.str_repeat('x', 48))->assertUnauthorized();
});

test('a token reaches only its own team, even when its owner is in others', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);

    $other = Team::factory()->create();
    Membership::query()->create(['team_id' => $other->id, 'user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $otherProject = Projects::create($other, 'other');

    $plain = Api::token($owner, $world->team);

    Api::get('projects', $plain)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $world->project->public_id);
    Api::get('projects/'.$otherProject->public_id, $plain)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('a token never exceeds its owner\'s role', function (): void {
    $world = DeploymentUi::make();
    $viewer = $world->member(TeamRole::Viewer);
    // Written directly: the create action refuses abilities the role can't use.
    $plain = Api::rawToken($viewer, $world->team, ['read', 'deploy', 'env:read', 'env:write', 'secrets:read']);
    $deployment = $world->latest($world->web);

    Api::call('POST', "deployments/{$deployment->public_id}/redeploy", $plain)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('removing a member deletes their tokens of that team', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    $developer = $world->member(TeamRole::Developer);
    $plain = Api::token($developer, $world->team, [ApiAbility::Read]);

    $membership = Membership::query()->where('team_id', $world->team->id)->where('user_id', $developer->id)->sole();
    resolve(RemoveMember::class)->handle($owner, $membership, AuditActor::system());

    expect(ApiToken::query()->where('tokenable_id', $developer->id)->count())->toBe(0);
    Api::get('me', $plain)->assertUnauthorized();
});

test('an unknown route under /api/v1 answers in the error format', function (): void {
    Api::get('nope', null)->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

test('the rate limit answers 429 rate_limited per token', function (): void {
    config(['api.rate_limits.requests_per_minute' => 2]);
    $world = DeploymentUi::make(false);
    $plain = Api::token($world->member(TeamRole::Owner), $world->team);

    Api::get('projects', $plain)->assertOk();
    Api::get('projects', $plain)->assertOk();
    Api::get('projects', $plain)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');
});
