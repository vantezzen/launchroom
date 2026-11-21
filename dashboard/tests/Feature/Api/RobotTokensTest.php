<?php

declare(strict_types=1);

use App\Actions\Teams\RemoveMember;
use App\Data\Audit\AuditActor;
use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Enums\TokenScopeLevel;
use App\Http\Api\TokenScopes;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
 * Robot tokens (D111, docs/16 §1.4, docs/09 R45): team-owned tokens with
 * abilities and an optional project or service scope.
 */

beforeEach(fn () => Projects::useTestKek());

/**
 * @param  array<string, mixed>  $overrides
 * @return array{token: array<string, mixed>, plainText: string}
 */
function createRobot(User $actor, array $overrides = []): array
{
    resolve('auth')->forgetGuards();

    /** @var array{token: array<string, mixed>, plainText: string} */
    return test()->actingAs($actor)
        ->postJson('/settings/robot-tokens', [
            'name' => 'GitHub Actions',
            'expiresInDays' => 90,
            'abilities' => ['read', 'deploy'],
            ...$overrides,
        ])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json();
}

test('owners and admins create robot tokens, developers and viewers only see them', function (TeamRole $role, bool $manages): void {
    $world = DeploymentUi::make(false);
    $actor = $world->member($role);
    createRobot($world->member(TeamRole::Owner));

    $this->actingAs($actor)->get('/settings/robot-tokens')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/robot-tokens')
            ->has('tokens', 1)
            ->missing('tokens.0.token')
            ->where('tokens.0.name', 'GitHub Actions')
            ->where('canManage', $manages));

    $response = $this->actingAs($actor)->postJson('/settings/robot-tokens', ['name' => 'CI', 'expiresInDays' => 30, 'abilities' => ['read']]);
    $manages ? $response->assertCreated() : $response->assertForbidden();

    $token = ApiToken::query()->where('client', 'robot')->firstOrFail();
    $this->actingAs($actor)->post("/settings/robot-tokens/{$token->public_id}/rotate")->assertStatus($manages ? 201 : 403);
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'developer' => [TeamRole::Developer, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('a robot token is shown once, audited, and acts with its abilities only', function (): void {
    $world = DeploymentUi::make();
    $owner = $world->member(TeamRole::Owner);
    $created = createRobot($owner, ['abilities' => ['read']]);

    $token = ApiToken::query()->where('client', 'robot')->sole();
    $robot = User::query()->findOrFail($token->tokenable_id);

    expect($created['plainText'])->toStartWith($token->id.'|lr_pat_')
        ->and($created['token']['createdBy'])->toBe($owner->name)
        ->and($robot->isRobot())->toBeTrue()
        ->and($robot->email)->toEndWith('@robots.launchroom.invalid')
        ->and($robot->email_verified_at)->toBeNull()
        ->and(Membership::query()->where('user_id', $robot->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'robot_token.created')->sole()->meta)->toMatchArray(['name' => 'GitHub Actions', 'abilities' => ['read'], 'project' => null]);

    Api::get('me', $created['plainText'])->assertOk();
    Api::get("environments/{$world->production->public_id}/services", $created['plainText'])->assertOk()->assertJsonCount(2, 'data');

    // Read only: deploying needs the deploy ability.
    $deployment = $world->latest($world->web);
    Api::call('POST', "deployments/{$deployment->public_id}/redeploy", $created['plainText'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'missing_ability');

    // The admin ceiling never reaches what no ability maps to.
    expect($robot->hasTeamPermission($world->team, Permission::ManageMembers))->toBeFalse()
        ->and($robot->hasTeamPermission($world->team, Permission::View))->toBeTrue();
});

test('a robot reaches its own team only', function (): void {
    $world = DeploymentUi::make(false);
    $other = DeploymentUi::make(false);
    $created = createRobot($world->member(TeamRole::Owner));

    Api::get("projects/{$other->project->public_id}", $created['plainText'])->assertNotFound();
    Api::get("projects/{$world->project->public_id}", $created['plainText'])->assertOk();

    // Another team's admin can't rotate or revoke it.
    $token = ApiToken::query()->where('client', 'robot')->sole();
    $this->actingAs($other->member(TeamRole::Admin))->post("/settings/robot-tokens/{$token->public_id}/rotate")->assertNotFound();
    $this->actingAs($other->member(TeamRole::Admin))->delete("/settings/robot-tokens/{$token->public_id}")->assertNotFound();
});

test('a robot outlives the member who created it, unlike their own tokens', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    $admin = $world->member(TeamRole::Admin);
    $robot = createRobot($admin);
    $personal = Api::token($admin, $world->team);

    resolve(RemoveMember::class)->handle($owner, Membership::query()->where('user_id', $admin->id)->sole(), AuditActor::system());

    Api::get('me', $personal)->assertUnauthorized();
    Api::get("projects/{$world->project->public_id}", $robot['plainText'])->assertOk();

    $this->actingAs($owner)->get('/settings/robot-tokens')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('tokens', 1)->where('tokens.0.createdBy', $admin->name));
});

test('a robot never signs in and is never a member', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    createRobot($owner);
    $robot = User::query()->whereNotNull('robot_team_id')->sole();

    resolve('auth')->forgetGuards();
    $this->flushSession();
    $this->post('/login', ['email' => $robot->email, 'password' => 'password']);
    $this->assertGuest();

    $this->actingAs($owner)->get('/settings/team')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('members', fn ($members): bool => collect($members)->doesntContain(fn (array $member): bool => $member['name'] === 'GitHub Actions')));

    // Even with a session, every dashboard page wants a verified address.
    $this->actingAs($robot)->get('/settings/robot-tokens')->assertRedirect();
});

test('rotate replaces the secret, revoke stops it and keeps the robot', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    $first = createRobot($owner, ['project' => $world->project->public_id, 'service' => $world->web->public_id]);
    $old = ApiToken::query()->where('client', 'robot')->sole();

    $rotated = $this->actingAs($owner)->post("/settings/robot-tokens/{$old->public_id}/rotate")->assertCreated()->json();
    $new = ApiToken::query()->where('client', 'robot')->sole();

    expect($new->id)->not->toBe($old->id)
        ->and($new->tokenable_id)->toBe($old->tokenable_id)
        ->and($new->abilities)->toBe($old->abilities)
        ->and($new->service_id)->toBe($world->web->id)
        ->and($new->expires_at?->isSameDay(now()->addDays(90)))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'robot_token.rotated')->exists())->toBeTrue();

    Api::get('me', $first['plainText'])->assertUnauthorized();
    Api::get('me', $rotated['plainText'])->assertOk();

    $this->actingAs($owner)->delete("/settings/robot-tokens/{$new->public_id}")->assertRedirect();
    Api::get('me', $rotated['plainText'])->assertUnauthorized();

    $robot = User::query()->whereNotNull('robot_team_id')->sole();
    expect(AuditEvent::query()->where('action', 'robot_token.revoked')->exists())->toBeTrue()
        ->and($robot->hasTeamPermission($world->team, Permission::View))->toBeFalse();
});

test('the scope must be in the team', function (): void {
    $world = DeploymentUi::make(false);
    $other = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);

    $this->actingAs($owner)->postJson('/settings/robot-tokens', ['name' => 'x', 'expiresInDays' => 30, 'abilities' => ['read'], 'project' => $other->project->public_id])->assertUnprocessable();
    $this->actingAs($owner)->postJson('/settings/robot-tokens', ['name' => 'x', 'expiresInDays' => 30, 'abilities' => ['read'], 'service' => $world->web->public_id])->assertUnprocessable();
    $this->actingAs($owner)->postJson('/settings/robot-tokens', ['name' => 'x', 'expiresInDays' => 30, 'abilities' => ['read'], 'project' => $world->project->public_id, 'service' => $other->web->public_id])->assertUnprocessable();
});

test('a service-scoped token stays on its service', function (): void {
    $world = DeploymentUi::make();
    $other = Projects::create($world->team, 'blog');
    $plain = createRobot($world->member(TeamRole::Owner), ['project' => $world->project->public_id, 'service' => $world->web->public_id])['plainText'];
    $env = $world->production->public_id;
    $web = $world->web->public_id;
    $postgres = $world->postgres->public_id;

    // Lists show what the scope covers.
    Api::get('projects', $plain)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $world->project->public_id);
    Api::get("environments/{$env}/services", $plain)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $web);

    // Its service: reads and deploys.
    Api::get("environments/{$env}/services/{$web}", $plain)->assertOk();
    Api::get("environments/{$env}/deployments?service={$web}", $plain)->assertOk();
    Api::call('POST', "environments/{$env}/deployments", $plain, ['service' => $web, 'redeploy' => true])->assertStatus(202);
    Api::get('deployments/'.$world->latest($world->web)->public_id, $plain)->assertOk();
    Api::get('deployments/'.$world->latest($world->web)->public_id.'/logs', $plain)->assertOk();

    // Another service, by path, by deployment or by input.
    Api::get("environments/{$env}/services/{$postgres}", $plain)->assertForbidden()->assertJsonPath('error.code', 'token_scope');
    Api::call('POST', 'deployments/'.$world->latest($world->postgres)->public_id.'/redeploy', $plain)->assertForbidden();
    Api::get('deployments/'.$world->latest($world->postgres)->public_id.'/logs', $plain)->assertForbidden();
    Api::call('POST', "environments/{$env}/deployments", $plain, ['service' => $postgres, 'redeploy' => true])->assertForbidden();
    Api::get("environments/{$env}/deployments", $plain)->assertForbidden();
    Api::get("environments/{$env}/deployments?service={$postgres}", $plain)->assertForbidden();

    // Wider routes and other projects.
    Api::call('POST', "environments/{$env}/deploy", $plain)->assertForbidden();
    Api::get('servers', $plain)->assertForbidden()->assertJsonPath('error.code', 'token_scope');
    Api::get("projects/{$other->public_id}", $plain)->assertForbidden();
    Api::get('me', $plain)->assertOk()
        ->assertJsonPath('data.scope.projectId', $world->project->public_id)
        ->assertJsonPath('data.scope.projectName', 'shop')
        ->assertJsonPath('data.scope.serviceId', $web)
        ->assertJsonPath('data.scope.serviceName', $world->web->name);

    expect(Deployment::query()->where('service_instance_id', $world->latest($world->postgres)->service_instance_id)->count())->toBe(1);
});

test('a project-scoped token reaches its project only', function (): void {
    $world = DeploymentUi::make();
    $other = Projects::create($world->team, 'blog');
    $plain = createRobot($world->member(TeamRole::Owner), ['project' => $world->project->public_id])['plainText'];
    $env = $world->production->public_id;

    Api::get("environments/{$env}/services", $plain)->assertOk()->assertJsonCount(2, 'data');
    Api::get("environments/{$env}/config/status", $plain)->assertOk();
    Api::get("environments/{$env}/deployments", $plain)->assertOk();
    Api::get("projects/{$other->public_id}", $plain)->assertForbidden();
    Api::get("environments/{$other->production()->sole()->public_id}/services", $plain)->assertForbidden();
    Api::get('security/findings', $plain)->assertForbidden();
    Api::get('me', $plain)->assertOk()
        ->assertJsonPath('data.scope.projectId', $world->project->public_id)
        ->assertJsonPath('data.scope.serviceId', null);
});

test('every authenticated API route has a token scope', function (): void {
    $missing = collect(Router::getRoutes()->getRoutes())
        ->filter(static fn (Route $route): bool => in_array('auth:sanctum', $route->gatherMiddleware(), true) && str_starts_with((string) $route->getName(), 'api.'))
        ->map(static fn (Route $route): string => (string) $route->getName())
        ->reject(static fn (string $name): bool => TokenScopes::of($name) instanceof TokenScopeLevel)
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

test('robot tokens never appear among a member\'s own tokens', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    createRobot($owner);

    $this->actingAs($owner)->get('/settings/api-tokens')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('tokens', 0));
});
