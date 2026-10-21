<?php

declare(strict_types=1);

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Services\RecordPlanResult;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskCompletion;
use App\Enums\DaemonTaskType;
use App\Enums\TeamRole;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Support\PublicId;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitHubFake;
use Tests\Support\Projects;

const PP_SHA = '4015b57a143aec5156fd1444a017a32137a3fd0f';

beforeEach(function (): void {
    GitHubFake::setUp();
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->repository = GitHubFake::repository(GitHubFake::installation(GitHubFake::app($this->team)), 555, 'octo-org/shop');
    $this->url = fn (string $branch = 'main', string $root = ''): string => route('projects.plan-preview', ['project' => $this->project, 'repository' => $this->repository->public_id, 'branch' => $branch, 'rootDirectory' => $root]);

    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/shop/commits/main' => Http::response(['sha' => PP_SHA, 'commit' => ['message' => 'Ship it']]),
        'https://api.github.com/repos/octo-org/shop/commits/gone' => Http::response(['message' => 'No commit found'], 422),
    ]);
});

/**
 * @param  array<string, mixed>  $completion
 */
function ppComplete(Server $server, DaemonTask $task, array $completion): void
{
    resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['plan']]));
    $task->refresh();

    resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt, ...$completion,
    ]));
}

test('without a build server the preview says detection happens with the first build', function (): void {
    $this->actingAs($this->developer)->getJson(($this->url)())
        ->assertOk()
        ->assertJson(['status' => 'unavailable', 'plan' => null]);

    expect(DaemonTask::query()->count())->toBe(0);
});

test('the preview starts one plan task, waits for it and shows what was detected', function (): void {
    [$server] = Projects::server($this->team);

    $this->actingAs($this->viewer)->getJson(($this->url)())->assertForbidden();

    $this->actingAs($this->developer)->getJson(($this->url)())
        ->assertOk()
        ->assertJson(['status' => 'pending', 'message' => 'Waiting for a build server']);

    $task = DaemonTask::query()->sole();
    expect($task->type)->toBe(DaemonTaskType::Plan)
        ->and($task->spec->repo->sha)->toBe(PP_SHA)
        ->and($task->spec->repo->cloneUrl)->toBe('https://github.com/octo-org/shop.git')
        ->and($task->spec->environment)->toBe($this->production->public_id)
        ->and(PublicId::isValid($task->spec->service, 'svc'))->toBeTrue()
        ->and(isset($task->spec->deployment))->toBeFalse()
        ->and($task->seal)->toHaveKey('cloneToken');

    // Polling looks the task up instead of starting another one.
    $this->actingAs($this->developer)->getJson(($this->url)())->assertJson(['status' => 'pending']);
    expect(DaemonTask::query()->count())->toBe(1);

    // Another root directory is another preview.
    $this->actingAs($this->developer)->getJson(($this->url)('main', 'apps/web'))->assertJson(['status' => 'pending']);
    expect(DaemonTask::query()->count())->toBe(2)
        ->and(DaemonTask::query()->latest('id')->first()?->spec->rootDir)->toBe('apps/web');

    ppComplete($server, $task, ['outcome' => 'succeeded', 'plan' => ['durationMs' => 900, 'summary' => [
        'builder' => 'railpack', 'builderReason' => 'preset', 'railpackVersion' => '0.42.0', 'provider' => 'node', 'framework' => 'next',
        'packageManager' => 'pnpm', 'port' => 3000,
        'packages' => [['name' => 'node', 'version' => '22.11.0', 'source' => '.nvmrc'], ['name' => 'pnpm', 'version' => '9.12.0']],
        'installCommand' => 'pnpm install --frozen-lockfile', 'buildCommand' => 'pnpm build', 'startCommand' => 'node server.js',
        'presets' => [['name' => 'nextjs', 'version' => 1, 'decisions' => [['id' => 'standalone-output', 'title' => 'Standalone output']]]],
        'envReferences' => ['NEXT_PUBLIC_API_URL', 'PORT'],
        'warnings' => [['code' => 'no-lockfile', 'message' => 'No lockfile found.']],
        'plan' => ['steps' => []],
    ]]]);

    $this->actingAs($this->developer)->getJson(($this->url)())
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('plan.framework', 'Next.js')
        ->assertJsonPath('plan.packageManager', 'pnpm 9.12.0')
        ->assertJsonPath('plan.runtime', 'Node 22.11.0')
        ->assertJsonPath('plan.runtimeSource', '.nvmrc')
        ->assertJsonPath('plan.port', 3000)
        ->assertJsonPath('plan.installCommand', 'pnpm install --frozen-lockfile')
        ->assertJsonPath('plan.presets.0.decisions.0.id', 'standalone-output')
        ->assertJsonPath('plan.envReferences', ['NEXT_PUBLIC_API_URL', 'PORT'])
        // Nothing is "missing" before the service exists.
        ->assertJsonPath('plan.missingVariables', [])
        ->assertJsonPath('plan.warnings', ['No lockfile found.'])
        ->assertJsonPath('plan.planJson', ['steps' => []]);

    // The preview task belongs to no service instance.
    resolve(RecordPlanResult::class)->handle($task->refresh());
    expect(ServiceInstance::query()->count())->toBe(0);
});

test('a failed plan or a missing branch is reported, not hidden', function (): void {
    [$server] = Projects::server($this->team);

    $this->actingAs($this->developer)->getJson(($this->url)('gone'))
        ->assertOk()
        ->assertJson(['status' => 'failed', 'message' => 'Branch gone does not exist in octo-org/shop.']);

    $this->actingAs($this->developer)->getJson(($this->url)())->assertJson(['status' => 'pending']);
    ppComplete($server, DaemonTask::query()->sole(), ['outcome' => 'failed', 'error' => 'railpack could not plan', 'failure' => ['class' => 'plan_error', 'step' => 'plan', 'retryable' => false]]);

    $this->actingAs($this->developer)->getJson(($this->url)())
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('message', 'Detection failed: railpack could not plan');
});

test('previews are scoped to the team’s repositories', function (): void {
    $foreign = GitHubFake::repository(GitHubFake::installation(GitHubFake::app(Team::factory()->create(), 4343), 101), 777, 'evil/repo');

    $this->actingAs($this->developer)
        ->getJson(route('projects.plan-preview', ['project' => $this->project, 'repository' => $foreign->public_id, 'branch' => 'main']))
        ->assertNotFound();

    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->getJson(($this->url)())->assertNotFound();
});
