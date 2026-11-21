<?php

declare(strict_types=1);

use App\Enums\ApiAbility;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Enums\HealthcheckType;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\Team;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\PreviewFlow;
use Tests\Support\Projects;

/*
| D111: a failed deployment explained through the API and its suggested fix
| applied with the dashboard's authorization; rays decoded with the trace
| page's single answer; a project's previews; resuming auto-deploy.
*/

beforeEach(fn () => Projects::useTestKek());

/**
 * The web service's live deployment turned into a health check failure the
 * catalogue explains as "doesn't answer HTTP" (fix: TCP health check), with
 * health step lines.
 */
function failedNotHttp(DeploymentUi $world): Deployment
{
    $deployment = $world->latest($world->web);
    $deployment->forceFill([
        'status' => DeploymentStatus::Failed,
        'failure_class' => DeploymentFailureClass::HealthTimeout,
        'failure_message' => 'GET / on :8000: malformed HTTP response "\x15\x03"',
    ])->save();
    $deployment->steps()->where('name', DeploymentStepName::Health->value)->update(['status' => DeploymentStepStatus::Failed->value]);

    $lines = array_map(static fn (int $i): string => "probe attempt {$i}", range(1, 20));
    $lines[3] = "\e[31merror\e[0m: connection reset by peer";
    $world->logs($deployment, $lines, step: 'health');

    return $deployment;
}

test('a failed deployment is explained with its failing step, last lines and fixes', function (): void {
    $world = DeploymentUi::make();
    $deployment = failedNotHttp($world);
    $token = Api::token($world->member(TeamRole::Viewer), $world->team, [ApiAbility::Read]);

    Api::get("deployments/{$deployment->public_id}", $token)
        ->assertOk()
        ->assertJsonPath('data.failure.class', 'health_timeout')
        ->assertJsonPath('data.failure.title', 'Health check timed out');

    $report = Api::get("deployments/{$deployment->public_id}/failure", $token)->assertOk();
    $excerpt = $report->json('data.excerpt');

    expect($report->json('data.explanation.key'))->toBe('health_not_http')
        ->and($report->json('data.failingStep'))->toBe('health')
        ->and($report->json('data.fixes.0.code'))->toBe('use_tcp_healthcheck')
        ->and($report->json('data.fixes.0.oneClick'))->toBeTrue()
        ->and($report->json('data.fixes.0.command'))->toBe("launchroom fix use_tcp_healthcheck --deployment {$deployment->public_id}")
        // The error line came before the tail: quoted with its context, ANSI removed.
        ->and($excerpt[1]['text'])->toBe('error: connection reset by peer')
        ->and($excerpt[1]['error'])->toBeTrue()
        ->and($report->json('data.errorLine'))->toBe($excerpt[1]['number'])
        ->and(array_column($excerpt, 'text'))->toContain('probe attempt 20')
        ->and(count($excerpt))->toBe(3 + 12);

    // A deployment that didn't fail has no report.
    $live = $world->latest($world->postgres);
    Api::get("deployments/{$live->public_id}/failure", $token)->assertStatus(422)->assertJsonPath('error.code', 'rule_violation');
});

test('the suggested fix is applied with the dashboard\'s authorization, and only that fix', function (): void {
    Queue::fake();
    $world = DeploymentUi::make();
    $deployment = failedNotHttp($world);
    $uri = "deployments/{$deployment->public_id}/fixes";

    // Both abilities: deploy without fix is a missing ability.
    $deployOnly = Api::rawToken($world->member(TeamRole::Owner), $world->team, ['read', 'deploy']);
    Api::call('POST', "{$uri}/use_tcp_healthcheck", $deployOnly)->assertForbidden()->assertJsonPath('error.ability', 'fix');

    // A viewer's full token is refused by the role.
    $viewer = Api::rawToken($world->member(TeamRole::Viewer), $world->team, ApiAbility::values());
    Api::call('POST', "{$uri}/use_tcp_healthcheck", $viewer)->assertForbidden()->assertJsonPath('error.code', 'forbidden');

    $developer = Api::token($world->member(TeamRole::Developer), $world->team);

    // A fix the catalogue doesn't suggest here never changes a setting; an unknown code is not found.
    Api::call('POST', "{$uri}/accept_4xx", $developer)->assertStatus(422)->assertJsonPath('error.code', 'rule_violation');
    Api::call('POST', "{$uri}/rm_rf", $developer)->assertNotFound();

    $applied = Api::call('POST', "{$uri}/use_tcp_healthcheck", $developer)
        ->assertStatus(202)
        ->assertJsonPath('data.outcome', 'deploying');

    $instance = ServiceInstance::query()->whereKey($deployment->service_instance_id)->sole();
    expect($instance->deploy->healthcheck->type)->toBe(HealthcheckType::Tcp)
        ->and($applied->json('data.deployment.id'))->toBe($world->latest($world->web)->public_id)
        ->and($world->latest($world->web)->id)->not->toBe($deployment->id);

    // Another team's deployment is not found.
    $stranger = Team::factory()->create();
    $strangerToken = Api::token(memberOf($stranger, TeamRole::Owner), $stranger);
    Api::get("deployments/{$deployment->public_id}/failure", $strangerToken)->assertNotFound();
    Api::call('POST', "{$uri}/use_tcp_healthcheck", $strangerToken)->assertNotFound();
});

test('a ray that can\'t be shown gets the trace page\'s single answer', function (): void {
    $world = DeploymentUi::make();
    $token = Api::token($world->member(TeamRole::Viewer), $world->team, [ApiAbility::Read]);

    Api::call('POST', 'traces/decode', $token, ['value' => 'X-Launchroom-Ray: lr1.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', "This ray isn't from one of your services.");
    Api::call('POST', 'traces/decode', $token, ['value' => 'lr2.AAAA'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'This ray was made by a newer version of launchroom. Update launchroom to read it.');
    Api::call('POST', 'traces/decode', null, ['value' => 'lr1.AAAA'])->assertUnauthorized();
});

test('a project\'s previews, narrowed to one pull request, with their deployments', function (): void {
    PreviewFlow::setUp();
    [$team, $server, , $project] = PreviewFlow::project();
    PreviewFlow::event($project);
    PreviewFlow::run($server);
    $token = Api::token(memberOf($team, TeamRole::Viewer), $team, [ApiAbility::Read]);

    $listed = Api::get("projects/{$project->public_id}/previews?pr=7", $token)->assertOk();

    expect($listed->json('data'))->toHaveCount(1)
        ->and($listed->json('data.0.number'))->toBe(7)
        ->and($listed->json('data.0.headSha'))->toBe(PreviewFlow::SHA_PR_1)
        ->and($listed->json('data.0.health'))->toBe('live')
        ->and($listed->json('data.0.urls'))->not->toBeEmpty()
        ->and(collect($listed->json('data.0.deployments'))->every(static fn (array $d): bool => $d['finished'] === true))->toBeTrue()
        // The app is built from the pull request's head (the database has no commit).
        ->and(collect($listed->json('data.0.deployments'))->where('serviceName', 'web')->value('commit.sha'))->toBe(PreviewFlow::SHA_PR_1);

    expect(Api::get("projects/{$project->public_id}/previews?pr=8", $token)->assertOk()->json('data'))->toBe([]);

    $stranger = Team::factory()->create();
    Api::get("projects/{$project->public_id}/previews", Api::token(memberOf($stranger, TeamRole::Owner), $stranger))->assertNotFound();
});

test('resuming auto-deploy after a rollback needs the deploy permission', function (): void {
    $world = DeploymentUi::make();
    // What a production rollback leaves behind (RollbackDeployment, docs/04 §8).
    $world->production->forceFill(['auto_deploy_paused' => true])->save();
    $uri = "environments/{$world->production->public_id}/auto-deploy/resume";

    expect($world->production->refresh()->auto_deploy_paused)->toBeTrue();

    Api::call('POST', $uri, Api::rawToken($world->member(TeamRole::Viewer), $world->team, ApiAbility::values()))->assertForbidden();
    Api::call('POST', $uri, Api::token($world->member(TeamRole::Developer), $world->team))
        ->assertOk()
        ->assertJsonPath('data.autoDeployPaused', false);
});
