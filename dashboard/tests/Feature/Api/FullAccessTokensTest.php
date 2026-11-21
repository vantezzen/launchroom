<?php

declare(strict_types=1);

use App\Enums\ApiAbility;
use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
 * "Full access" tokens (D113, docs/16 §1.2, docs/09 R11): a flag that
 * includes abilities added later, never more than the holder's role (or a
 * robot's team ceiling) and never outside a robot's scope.
 */

beforeEach(fn () => Projects::useTestKek());

/** @return list<string> */
function allAbilityValues(): array
{
    return ApiAbility::values();
}

test('a full-access token from Settings records the flag and lists every ability', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);

    $created = $this->actingAs($owner)
        ->postJson('/settings/api-tokens', ['name' => 'laptop', 'expiresInDays' => 30, 'fullAccess' => true])
        ->assertCreated()
        ->assertJsonPath('token.fullAccess', true)
        ->assertJsonPath('token.abilities', allAbilityValues())
        ->json();

    $token = ApiToken::query()->sole();

    expect($token->full_access)->toBeTrue()
        ->and($token->abilities)->toBe(allAbilityValues())
        ->and(AuditEvent::query()->where('action', 'api_token.created')->sole()->meta)->toMatchArray(['full_access' => true]);

    Api::get('me', $created['plainText'])->assertOk()
        ->assertJsonPath('data.token.fullAccess', true)
        ->assertJsonPath('data.token.abilities', allAbilityValues());

    $this->actingAs($owner)->get('/settings/api-tokens')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('tokens.0.fullAccess', true));
});

test('a custom token keeps its list', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);

    $this->actingAs($owner)
        ->postJson('/settings/api-tokens', ['name' => 'ci', 'expiresInDays' => 30, 'abilities' => ['read', 'deploy']])
        ->assertCreated()
        ->assertJsonPath('token.fullAccess', false)
        ->assertJsonPath('token.abilities', ['read', 'deploy']);

    expect(ApiToken::query()->sole()->full_access)->toBeFalse();
});

test('full access includes abilities added after the token was created', function (): void {
    $world = DeploymentUi::make();
    $owner = $world->member(TeamRole::Owner);
    // Stored before `deploy`, `run`, `fix` or `update` existed, for the sake of the test.
    $full = Api::rawToken($owner, $world->team, ['read'], fullAccess: true);
    $custom = Api::rawToken($owner, $world->team, ['read']);
    $deployment = $world->latest($world->web)->public_id;

    Api::call('POST', "deployments/{$deployment}/redeploy", $custom)->assertForbidden()->assertJsonPath('error.code', 'missing_ability');
    Api::call('POST', "deployments/{$deployment}/redeploy", $full)->assertStatus(202);

    Api::get('me', $full)->assertOk()->assertJsonPath('data.token.abilities', allAbilityValues());

    $token = ApiToken::query()->where('full_access', true)->sole();

    foreach (ApiAbility::cases() as $ability) {
        expect($token->hasAbility($ability))->toBeTrue();
    }
});

test('full access never exceeds the holder\'s role', function (): void {
    $world = DeploymentUi::make();
    $viewer = $world->member(TeamRole::Viewer);
    $developer = $world->member(TeamRole::Developer);
    $owner = $world->member(TeamRole::Owner);
    $deployment = $world->latest($world->web)->public_id;

    $viewerToken = Api::rawToken($viewer, $world->team, ['read'], fullAccess: true);
    Api::get('me', $viewerToken)->assertOk()->assertJsonPath('data.token.abilities', ['read', 'env:read']);
    Api::call('POST', "deployments/{$deployment}/redeploy", $viewerToken)->assertForbidden();

    $developerToken = Api::rawToken($developer, $world->team, ['read'], fullAccess: true);
    Api::get('me', $developerToken)->assertOk()
        ->assertJsonPath('data.token.abilities', ['read', 'deploy', 'env:read', 'env:write', 'domains:write', 'fix', 'update']);

    $token = ApiToken::query()->where('tokenable_id', $developer->id)->sole();
    expect($token->hasAbility(ApiAbility::SecretsRead))->toBeFalse()
        ->and($token->hasAbility(ApiAbility::Run))->toBeFalse()
        ->and($token->hasAbility(ApiAbility::Deploy))->toBeTrue();

    // Issued by the real action: the record is what the role allows, nothing refused.
    $this->actingAs($developer)
        ->postJson('/settings/api-tokens', ['name' => 'dev', 'expiresInDays' => 30, 'fullAccess' => true])
        ->assertCreated()
        ->assertJsonPath('token.abilities', ['read', 'deploy', 'env:read', 'env:write', 'domains:write', 'fix', 'update']);

    // A role change moves the ceiling with it.
    Membership::query()->where('user_id', $developer->id)->update(['role' => TeamRole::Admin->value]);
    User::membershipsChanged();
    Api::get('me', $developerToken)->assertOk()->assertJsonPath('data.token.abilities', allAbilityValues());

    // Even an owner's full-access token never reaches what no ability maps to.
    Api::rawToken($owner, $world->team, ['read'], fullAccess: true);
    $ownerToken = ApiToken::query()->where('tokenable_id', $owner->id)->sole();
    $owner->withAccessToken($ownerToken);
    expect($owner->hasTeamPermission($world->team, Permission::ManageMembers))->toBeFalse()
        ->and($owner->hasTeamPermission($world->team, Permission::ManageServers))->toBeFalse()
        ->and($owner->hasTeamPermission($world->team, Permission::RevealSecrets))->toBeTrue();
});

test('a full-access robot stays inside its scope and keeps full access when rotated', function (): void {
    $world = DeploymentUi::make();
    $owner = $world->member(TeamRole::Owner);
    $env = $world->production->public_id;

    $created = $this->actingAs($owner)
        ->postJson('/settings/robot-tokens', [
            'name' => 'Agent',
            'expiresInDays' => 30,
            'fullAccess' => true,
            'project' => $world->project->public_id,
            'service' => $world->web->public_id,
        ])
        ->assertCreated()
        ->assertJsonPath('token.fullAccess', true)
        ->json();
    $plain = $created['plainText'];

    Api::get('me', $plain)->assertOk()
        ->assertJsonPath('data.token.fullAccess', true)
        ->assertJsonPath('data.token.abilities', allAbilityValues());
    Api::call('POST', 'deployments/'.$world->latest($world->web)->public_id.'/redeploy', $plain)->assertStatus(202);
    Api::call('POST', 'deployments/'.$world->latest($world->postgres)->public_id.'/redeploy', $plain)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'token_scope');
    Api::get('servers', $plain)->assertForbidden()->assertJsonPath('error.code', 'token_scope');
    Api::get("environments/{$env}/services/{$world->postgres->public_id}", $plain)->assertForbidden();

    $robot = User::query()->findOrFail(ApiToken::query()->where('client', 'robot')->sole()->tokenable_id);
    resolve('auth')->forgetGuards();
    expect($robot->hasTeamPermission($world->team, Permission::ManageMembers))->toBeFalse()
        ->and($robot->hasTeamPermission($world->team, Permission::Deploy))->toBeTrue();

    $old = ApiToken::query()->where('client', 'robot')->sole();
    $this->actingAs($owner)->post("/settings/robot-tokens/{$old->public_id}/rotate")
        ->assertCreated()
        ->assertJsonPath('token.fullAccess', true);

    expect(ApiToken::query()->where('client', 'robot')->sole()->full_access)->toBeTrue();
});

test('a CLI sign-in can be approved with full access, and login --full-access preselects it', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    $session = Api::call('POST', 'cli/sessions', null, ['clientName' => 'launchroom-cli', 'hostname' => 'laptop', 'version' => '0.1.0'])->json('data');

    $this->actingAs($owner)->get("/cli/login?code={$session['userCode']}&access=full")
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('suggestedFullAccess', true));
    $this->actingAs($owner)->get("/cli/login?code={$session['userCode']}")
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('suggestedFullAccess', false));

    $this->actingAs($owner)
        ->post("/cli/login/{$session['userCode']}/approve", ['team' => $world->team->public_id, 'fullAccess' => true, 'expiresInDays' => 30])
        ->assertRedirect();

    Api::call('POST', 'cli/sessions/token', null, ['deviceCode' => $session['deviceCode']])
        ->assertOk()
        ->assertJsonPath('data.fullAccess', true)
        ->assertJsonPath('data.abilities', allAbilityValues());
});

test('the migration marks tokens that had the full set of their time as full access, and nothing else', function (): void {
    $world = DeploymentUi::make(false);
    $owner = $world->member(TeamRole::Owner);
    $developer = $world->member(TeamRole::Developer);
    $viewer = $world->member(TeamRole::Viewer);
    $leaver = $world->member(TeamRole::Admin);

    $migration = require database_path('migrations/2027_02_09_100000_add_full_access_to_api_tokens.php');
    $migration->down();

    $seven = ['read', 'deploy', 'env:read', 'env:write', 'secrets:read', 'domains:write', 'data:transfer'];
    $row = function (User $user, array $abilities) use ($world): int {
        $id = DB::table('personal_access_tokens')->insertGetId([
            'public_id' => 'tok_'.bin2hex(random_bytes(8)),
            'tokenable_type' => 'user',
            'tokenable_id' => $user->id,
            'name' => 'old',
            'token' => hash('sha256', random_bytes(16)),
            'abilities' => json_encode($abilities),
            'team_id' => $world->team->id,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $id;
    };

    $robot = new User;
    $robot->forceFill(['name' => 'CI', 'email' => 'robot-x@robots.launchroom.invalid', 'password' => 'x', 'robot_team_id' => $world->team->id, 'current_team_id' => $world->team->id])->save();

    $full = [
        'owner, original seven (any order)' => $row($owner, array_reverse($seven)),
        'owner, + run' => $row($owner, [...$seven, 'run']),
        'owner, + run + fix' => $row($owner, [...$seven, 'run', 'fix']),
        'owner, all ten' => $row($owner, [...$seven, 'run', 'fix', 'update']),
        'developer, seven within the role' => $row($developer, ['read', 'deploy', 'env:read', 'env:write', 'domains:write']),
        'developer, + fix' => $row($developer, ['read', 'deploy', 'env:read', 'env:write', 'domains:write', 'fix']),
        'developer, + fix + update' => $row($developer, ['read', 'deploy', 'env:read', 'env:write', 'domains:write', 'fix', 'update']),
        'robot, + run' => $row($robot, [...$seven, 'run']),
    ];
    $custom = [
        'owner, + fix without run' => $row($owner, [...$seven, 'fix']),
        'owner, Deploy' => $row($owner, ['read', 'deploy', 'env:read', 'env:write']),
        'owner, Deploy with fix' => $row($owner, ['read', 'deploy', 'env:read', 'env:write', 'fix']),
        'owner, read only' => $row($owner, ['read']),
        'owner, one short' => $row($owner, array_slice($seven, 0, 6)),
        'developer, owner\'s full set' => $row($developer, $seven),
        'viewer, everything a viewer had' => $row($viewer, ['read', 'env:read']),
        'member who left' => $row($leaver, [...$seven, 'run', 'fix', 'update']),
    ];
    Membership::query()->where('user_id', $leaver->id)->delete();

    $migration->up();

    $flags = DB::table('personal_access_tokens')->pluck('full_access', 'id');

    foreach ($full as $case => $id) {
        expect((bool) $flags[$id])->toBeTrue($case);
    }

    foreach ($custom as $case => $id) {
        expect((bool) $flags[$id])->toBeFalse($case);
    }
});
