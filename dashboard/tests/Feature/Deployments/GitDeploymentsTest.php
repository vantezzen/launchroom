<?php

declare(strict_types=1);

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Services\CreateService;
use App\Contracts\Git\TrackedRefs;
use App\Data\Audit\AuditActor;
use App\Data\Git\CommitData;
use App\Data\Git\DeploymentStatusReport;
use App\Data\Git\TrackedRef;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Services\CreateServiceData;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\Git\DeploymentReportState;
use App\Enums\Git\GitEventSource;
use App\Enums\ServiceKind;
use App\Events\Git\GitPushReceived;
use App\Events\Git\PullRequestEventReceived;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Git\ReportDeploymentStatusJob;
use App\Listeners\Git\DeployOnGitPush;
use App\Listeners\Git\ReportDeploymentToGitHub;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Git\GitHubInstallation;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitHubFake;
use Tests\Support\Projects;

const GD_SHA_1 = '1111111111111111111111111111111111111111';
const GD_SHA_2 = '2222222222222222222222222222222222222222';

beforeEach(function (): void {
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    [$this->server, $this->keypair] = Projects::server($this->team);
    $this->installation = GitHubFake::installation(GitHubFake::app($this->team));
    GitHubFake::repository($this->installation, 555, 'octo-org/web');
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse('ghs_clone_token'),
        'https://api.github.com/repos/octo-org/web/commits/main' => Http::response(['sha' => GD_SHA_1, 'commit' => ['message' => 'init']]),
        'https://api.github.com/*' => Http::response(['id' => 1], 201),
    ]);
    $this->project = Projects::create($this->team);
    $this->web = resolve(CreateService::class)->handle($this->project, CreateServiceData::from([
        'name' => 'web', 'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'repositoryId' => 555, 'fullName' => 'octo-org/web', 'installationId' => $this->installation->id],
    ]), null, AuditActor::system());
    $this->production = $this->project->production()->sole();
});

function gdPush(GitHubInstallation $installation, string $sha, string $ref = 'refs/heads/main', ?array $files = null, int $repositoryId = 555): void
{
    event(new GitPushReceived($installation, $repositoryId, $ref, GD_SHA_1, $sha, new CommitData($sha, 'ship it', 'Ada', 'ada@example.com', 'ada'), $files, false, GitEventSource::Webhook));
}

function gdDeployments(Service $service): int
{
    return Deployment::query()->whereIn('service_instance_id', ServiceInstance::query()->select('id')->where('service_id', $service->id))->count();
}

test('GitHub sources resolve the branch head and clone with a token sealed at lease time', function (): void {
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $deployment = Deployment::query()->latest('id')->firstOrFail();
    $task = DaemonTask::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->commit_sha)->toBe(GD_SHA_1)
        ->and($task->spec->repo->provider)->toBe('github')
        ->and($task->spec->repo->cloneUrl)->toBe('https://github.com/octo-org/web.git')
        ->and(json_encode($task))->not->toContain('ghs_clone_token');

    $leased = resolve(LeaseTasks::class)->handle($this->server, LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['build']]));
    $token = json_decode((string) sodium_crypto_box_seal_open((string) base64_decode($leased[0]->spec->cloneToken->sealed, true), $this->keypair), true);

    expect($token)->toBe(['serverId' => $this->server->public_id, 'taskId' => $task->public_id, 'purpose' => 'clone', 'token' => 'ghs_clone_token']);
});

test('a push deploys the commit with the last applied configuration', function (): void {
    gdPush($this->installation, GD_SHA_2);
    expect(gdDeployments($this->web))->toBe(0); // never deployed: nothing to push to

    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    gdPush($this->installation, GD_SHA_2);

    $deployment = Deployment::query()->latest('id')->firstOrFail();
    expect($deployment->trigger)->toBe(DeploymentTrigger::Push)
        ->and($deployment->commit_sha)->toBe(GD_SHA_2)
        ->and($deployment->git_ref)->toBe('refs/heads/main')
        ->and($deployment->commit_message)->toBe('ship it')
        ->and($deployment->commit_author)->toBe('ada')
        ->and(Deployment::query()->where('commit_sha', GD_SHA_1)->sole()->status)->toBe(DeploymentStatus::Superseded);
});

test('pushes are matched by installation, repository, branch, watch paths and paused auto-deploy', function (): void {
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $before = gdDeployments($this->web);

    gdPush($this->installation, GD_SHA_2, 'refs/heads/develop');
    gdPush($this->installation, GD_SHA_2, repositoryId: 556);
    gdPush(GitHubFake::installation(GitHubFake::app(Team::factory()->create(), 77), 300), GD_SHA_2);
    expect(gdDeployments($this->web))->toBe($before);

    $instance = ServiceInstance::query()->where('service_id', $this->web->id)->sole();
    $instance->forceFill(['build' => [...$instance->build->toArray(), 'watchPaths' => ['apps/web']]])->save();
    gdPush($this->installation, GD_SHA_2, files: ['docs/readme.md']);
    expect(gdDeployments($this->web))->toBe($before);

    Environment::query()->whereKey($this->production->id)->update(['auto_deploy_paused' => true]);
    gdPush($this->installation, GD_SHA_2, files: ['apps/web/index.js']);
    expect(gdDeployments($this->web))->toBe($before);

    Environment::query()->whereKey($this->production->id)->update(['auto_deploy_paused' => false]);
    gdPush($this->installation, GD_SHA_2, files: ['apps/web/index.js']);
    expect(gdDeployments($this->web))->toBe($before + 1);
});

test('watch paths: prefixes and globs, unknown file lists always deploy', function (?array $files, bool $deploys): void {
    expect(DeployOnGitPush::watched(['apps/web', 'packages/*/src/**'], '', $files))->toBe($deploys);
})->with([
    'unknown' => [null, true],
    'prefix' => [['apps/web/a.ts'], true],
    'glob' => [['packages/ui/src/x/button.tsx'], true],
    'unrelated' => [['docs/a.md', 'apps/website/x'], false],
]);

test('deployment status changes are reported to GitHub with the state mapping', function (): void {
    Queue::fake([ReportDeploymentStatusJob::class]);
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    gdPush($this->installation, GD_SHA_2);

    $states = [];
    Queue::assertPushed(ReportDeploymentStatusJob::class, function (ReportDeploymentStatusJob $job) use (&$states): bool {
        $states[] = $job->report->commitSha.':'.$job->report->state->value;

        return $job->report->installationId === $this->installation->id && $job->report->repositoryId === 555;
    });

    expect($states)->toBe([
        GD_SHA_1.':in_progress', // queued → building
        GD_SHA_1.':cancelled',   // superseded by the push
        GD_SHA_2.':queued',      // the push
        GD_SHA_2.':in_progress',
    ]);

    expect(ReportDeploymentToGitHub::state(DeploymentStatus::Routing))->toBe(DeploymentReportState::InProgress)
        ->and(ReportDeploymentToGitHub::state(DeploymentStatus::Active))->toBe(DeploymentReportState::Success)
        ->and(ReportDeploymentToGitHub::state(DeploymentStatus::Failed))->toBe(DeploymentReportState::Failure)
        ->and(ReportDeploymentToGitHub::state(DeploymentStatus::Superseded))->toBe(DeploymentReportState::Cancelled);
});

test('image and database services are never reported', function (): void {
    Queue::fake([ReportDeploymentStatusJob::class]);
    Projects::postgres($this->project);
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());

    Queue::assertPushed(ReportDeploymentStatusJob::class, fn (ReportDeploymentStatusJob $job): bool => $job->report->serviceName === 'web');
    Queue::assertNotPushed(ReportDeploymentStatusJob::class, fn (ReportDeploymentStatusJob $job): bool => $job->report->serviceName !== 'web');
});

test('tracked refs come from live GitHub-sourced instances and their trigger branches', function (): void {
    $refs = iterator_to_array(resolve(TrackedRefs::class)->all(), false);

    expect($refs)->toEqual([new TrackedRef($this->installation->id, 555, 'refs/heads/main')]);
});

test('pull request events are ignored until previews exist', function (): void {
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $before = Deployment::query()->count();

    event(new PullRequestEventReceived($this->installation, 555, 'opened', 7, 'Feature', GD_SHA_2, 'feature', 'main', 555, 555, false, 'ada', null, false, false, []));

    expect(Deployment::query()->count())->toBe($before);
});

test('a suspended installation fails the build lease with a clone error', function (): void {
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $deployment = Deployment::query()->latest('id')->firstOrFail();
    $this->installation->forceFill(['suspended_at' => now()])->save();

    $leased = resolve(LeaseTasks::class)->handle($this->server, LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['build']]));

    expect($leased)->toBe([])
        ->and($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_message)->toContain('suspended');
});

test('reported deployments carry links and the environment URL', function (): void {
    Queue::fake([ReportDeploymentStatusJob::class]);
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());

    Queue::assertPushed(ReportDeploymentStatusJob::class, function (ReportDeploymentStatusJob $job): bool {
        $report = $job->report;

        return $report instanceof DeploymentStatusReport
            && str_contains($report->deploymentUrl, '/d/dpl_')
            && $report->productionEnvironment
            && ! $report->transientEnvironment
            && str_starts_with((string) $report->environmentUrl, 'https://');
    });
});

test('completing a task of another server is refused', function (): void {
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    resolve(LeaseTasks::class)->handle($this->server, LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['build']]));
    [$other] = Projects::server($this->team, ip: '203.0.113.9');
    $task = DaemonTask::query()->where('type', 'build')->sole();

    resolve(CompleteTask::class)->handle($other, $task, TaskCompletion::validateAndCreate(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => 1, 'outcome' => 'succeeded']));
})->throws(DomainRuleViolation::class);
