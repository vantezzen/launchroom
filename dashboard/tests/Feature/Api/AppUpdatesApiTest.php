<?php

declare(strict_types=1);

use App\Actions\AppUpdates\CheckAppUpdates;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\ApiAbility;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\AppUpdates\AppUpdate;
use App\Models\AuditEvent;
use App\Models\ServiceInstance;
use App\Models\Team;
use Tests\Support\Api;
use Tests\Support\AppUpdates;
use Tests\Support\Backups;
use Tests\Support\Projects;

/*
| App updates through the API (D106, docs/16 §2.2): the list of available
| updates and applying one, with the dashboard's rules and authorization.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    AppUpdates::fakeDns();
    AppUpdates::fakeHub(['v6.2.0', 'v6.3.0', 'v7.0.1']);
});

function apiUpdatePath(ServiceInstance $instance): string
{
    $instance->loadMissing(['service', 'environment']);

    return "environments/{$instance->environment->public_id}/services/{$instance->service->public_id}/update";
}

/**
 * @return array{Team, ServiceInstance}
 */
function checkedListmonk(): array
{
    [$team, , $instance] = AppUpdates::listmonk();
    resolve(CheckAppUpdates::class)->handle($instance->id);

    return [$team, $instance->refresh()];
}

test('the list shows what the check found, without secrets or other teams', function (): void {
    [$team, $instance] = checkedListmonk();
    checkedListmonk();
    $token = Api::token(memberOf($team, TeamRole::Viewer), $team, [ApiAbility::Read]);

    $response = Api::get('app-updates', $token)->assertOk()->assertJsonCount(1, 'data');

    $response->assertJsonPath('data.0.serviceId', $instance->service->public_id)
        ->assertJsonPath('data.0.running', 'v6.2.0')
        ->assertJsonPath('data.0.status', 'available')
        ->assertJsonPath('data.0.recommended.tag', 'v6.3.0')
        ->assertJsonPath('data.0.major.tag', 'v7.0.1')
        ->assertJsonPath('data.0.canUpdate', false);
    expect(collect($response->json('data.0.candidates'))->pluck('tag')->all())->toBe(['v7.0.1', 'v6.3.0'])
        ->and($response->json('data.0'))->not->toHaveKeys(['logo', 'standsOut', 'window']);
});

test('the list narrows to a project of the team, and a project-scoped token to its own', function (): void {
    [$team, $instance] = checkedListmonk();
    [, , $other] = AppUpdates::listmonk($team);
    resolve(CheckAppUpdates::class)->handle($other->id);
    $owner = memberOf($team, TeamRole::Owner);
    $project = $instance->service->project->public_id;
    $token = Api::token($owner, $team, [ApiAbility::Read]);

    Api::get('app-updates', $token)->assertOk()->assertJsonCount(2, 'data');
    Api::get("app-updates?project={$project}", $token)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.projectId', $project);
    Api::get('app-updates?project='.Projects::create(Team::factory()->create(), 'elsewhere')->public_id, $token)->assertNotFound();
    Api::get('app-updates?project=nope', $token)->assertNotFound();

    $scoped = Api::rawToken($owner, $team, ['read']);
    ApiToken::query()->whereKey((int) strtok($scoped, '|'))->update(['project_id' => $other->service->project_id]);

    Api::get('app-updates', $scoped)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.serviceId', $other->service->public_id);
    Api::get("app-updates?project={$project}", $scoped)->assertNotFound();
});

test('applying needs update and deploy, and follows the dashboard\'s rules', function (): void {
    [$team, $instance] = checkedListmonk();
    $developer = memberOf($team, TeamRole::Developer);

    Api::call('POST', apiUpdatePath($instance), Api::token($developer, $team, [ApiAbility::Read, ApiAbility::Deploy]), ['tag' => 'v6.3.0'])
        ->assertForbidden()->assertJsonPath('error.ability', 'update');

    $token = Api::token($developer, $team, [ApiAbility::Read, ApiAbility::Deploy, ApiAbility::Update]);

    // Only a version the check found; a major only with the release notes read.
    Api::call('POST', apiUpdatePath($instance), $token, ['tag' => 'v9.9.9'])->assertUnprocessable();
    Api::call('POST', apiUpdatePath($instance), $token, ['tag' => 'v7.0.1'])->assertUnprocessable()->assertJsonPath('error.fields.releaseNotesRead.0', fn (string $message): bool => str_contains($message, 'release notes'));
    expect(AppUpdate::query()->exists())->toBeFalse();

    Api::call('POST', apiUpdatePath($instance), $token, ['tag' => 'v6.3.0'])
        ->assertStatus(202)
        ->assertJsonPath('data.run.toVersion', 'v6.3.0')
        ->assertJsonPath('data.run.fromVersion', 'v6.2.0');

    expect(AppUpdate::query()->sole()->to_version)->toBe('v6.3.0')
        ->and(AuditEvent::query()->where('action', AuditAction::AppUpdateStarted->value)->exists())->toBeTrue();
});

test('a viewer\'s token never updates, whatever its abilities say', function (): void {
    [$team, $instance] = checkedListmonk();
    $viewer = memberOf($team, TeamRole::Viewer);

    Api::call('POST', apiUpdatePath($instance), Api::rawToken($viewer, $team, ['read', 'deploy', 'update']), ['tag' => 'v6.3.0'])->assertForbidden();
    expect(AppUpdate::query()->exists())->toBeFalse();
});

test('applying is refused while other changes are staged', function (): void {
    [$team, $instance] = checkedListmonk();
    resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['deploy' => [...$instance->deploy->toArray(), 'drainSeconds' => 20]]));
    $token = Api::token(memberOf($team, TeamRole::Owner), $team, [ApiAbility::Read, ApiAbility::Deploy, ApiAbility::Update]);

    $response = Api::call('POST', apiUpdatePath($instance), $token, ['tag' => 'v6.3.0']);

    expect($response->getStatusCode())->toBeIn([409, 422])
        ->and((string) $response->getContent())->toContain('staged change')
        ->and(AppUpdate::query()->exists())->toBeFalse();
});

test('another team\'s service is not found', function (): void {
    [, $instance] = checkedListmonk();
    $stranger = Team::factory()->create();
    $token = Api::token(memberOf($stranger, TeamRole::Owner), $stranger, [ApiAbility::Read, ApiAbility::Deploy, ApiAbility::Update]);

    Api::call('POST', apiUpdatePath($instance), $token, ['tag' => 'v6.3.0'])->assertNotFound();
});
