<?php

declare(strict_types=1);

use App\Actions\Deployments\AdvanceDeployment;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\EvaluateDeploymentChecks;
use App\Actions\Deployments\RedeployService;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Environments\EnvironmentTriggers;
use App\Data\Git\CommitCheckData;
use App\Data\Git\CommitData;
use App\Data\Git\RepositoryRef;
use App\Data\Services\CreateServiceData;
use App\Enums\ApiAbility;
use App\Enums\AuditAction;
use App\Enums\CheckGateState;
use App\Enums\DeploymentStatus;
use App\Enums\Git\CommitCheckState;
use App\Enums\Git\GitEventSource;
use App\Enums\Git\GitProviderKind;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Events\Git\GitPushReceived;
use App\Jobs\AdvanceDeploymentJob;
use App\Jobs\Deployments\EvaluateDeploymentChecksJob;
use App\Jobs\Git\ProcessWebhookDeliveryJob;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Git\WebhookDelivery;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Services\Git\Providers\GitLabProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Api;
use Tests\Support\GitHubFake;
use Tests\Support\GitProviderFake;
use Tests\Support\Projects;

/*
| Deploy after checks pass (D111, docs/06 §2.5): a push to an environment
| that waits for checks stays queued until the commit's checks passed,
| starts when none appear within the grace period, and is cancelled when a
| check fails or the deadline passes. Webhooks nudge, the minute poll is the
| fallback, Deploy now skips the wait.
*/

const DAC_SHA = '5555555555555555555555555555555555555555';
const DAC_NEXT = '6666666666666666666666666666666666666666';

beforeEach(function (): void {
    Projects::useTestKek();
    Queue::fake();
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    Projects::server($this->team);
    $this->githubApp = GitHubFake::app($this->team);
    $this->installation = GitHubFake::installation($this->githubApp);
    GitHubFake::repository($this->installation, 555, 'octo-org/web');

    // What GitHub answers: check runs and commit statuses per test.
    $this->runs = [];
    $this->statuses = [];
    $this->githubDown = false;
    Http::fake(function (Request $request) {
        $url = $request->url();

        if (! str_starts_with($url, GitHubFake::API)) {
            return null;
        }

        if (str_contains($url, '/access_tokens')) {
            return GitHubFake::tokenResponse();
        }

        if (str_contains($url, '/check-runs?') || str_ends_with($url, '/check-runs')) {
            return $this->githubDown ? Http::response([], 502) : Http::response(['total_count' => count($this->runs), 'check_runs' => $this->runs]);
        }

        if (str_contains($url, '/status?') || str_ends_with($url, '/status')) {
            return $this->githubDown ? Http::response([], 502) : Http::response(['state' => 'pending', 'statuses' => $this->statuses]);
        }

        return Http::response(['id' => 1], 201);
    });

    $this->project = Projects::create($this->team);
    $this->web = resolve(CreateService::class)->handle($this->project, CreateServiceData::from([
        'name' => 'web', 'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'repositoryId' => 555, 'fullName' => 'octo-org/web', 'installationId' => $this->installation->id],
    ]), null, AuditActor::system());
    $this->production = $this->project->production()->sole();
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    Deployment::query()->update(['status' => DeploymentStatus::Active->value]);
    ServiceInstance::query()->where('service_id', $this->web->id)->update(['active_deployment_id' => Deployment::query()->value('id')]);

    Environment::query()->whereKey($this->production->id)->update(['triggers' => json_encode(new EnvironmentTriggers(branch: 'main', waitForChecks: true, checksTimeoutMinutes: 30))]);
});

function dacPush(object $test, string $sha = DAC_SHA): ?Deployment
{
    event(new GitPushReceived($test->installation, 555, 'refs/heads/main', str_repeat('0', 40), $sha, new CommitData($sha, 'ship', 'Ada', 'ada@example.com', 'ada'), null, false, GitEventSource::Webhook));

    return Deployment::query()->where('commit_sha', $sha)->latest('id')->first();
}

function dacRun(string $name, string $status = 'completed', ?string $conclusion = 'success', int $appId = 15368): array
{
    return ['name' => $name, 'status' => $status, 'conclusion' => $conclusion, 'html_url' => "https://github.com/octo-org/web/runs/{$name}", 'app' => ['id' => $appId]];
}

function dacEvaluate(Deployment $deployment): Deployment
{
    resolve(EvaluateDeploymentChecks::class)->handle($deployment->id);

    return $deployment->refresh();
}

test('a push waits in the queue for its checks and AdvanceDeployment leaves it there', function (): void {
    $deployment = dacPush($this);

    expect($deployment)->not->toBeNull()
        ->and($deployment->status)->toBe(DeploymentStatus::Queued)
        ->and($deployment->checks?->state)->toBe(CheckGateState::Waiting)
        ->and($deployment->checks->deadlineAt()->diffInMinutes($deployment->checks->sinceAt(), true))->toEqual(30.0)
        ->and($deployment->waitsForChecks())->toBeTrue();

    Queue::assertPushed(EvaluateDeploymentChecksJob::class, fn (EvaluateDeploymentChecksJob $job): bool => $job->deploymentId === $deployment->id);
    Queue::assertNotPushed(AdvanceDeploymentJob::class, fn (AdvanceDeploymentJob $job): bool => $job->deploymentId === $deployment->id);

    resolve(AdvanceDeployment::class)->handle($deployment->id);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Queued);
});

test('pending checks keep it waiting; once all pass it starts, launchroom’s own reports left out', function (): void {
    $deployment = dacPush($this);
    $this->runs = [dacRun('test', 'in_progress', null), dacRun('lint'), dacRun('launchroom: web (production)', 'queued', null, 4242)];
    $this->statuses = [
        ['context' => 'ci/circleci: build', 'state' => 'success', 'target_url' => 'https://circleci.com/gh/octo-org/web/1'],
        ['context' => 'launchroom: web (production)', 'state' => 'pending', 'target_url' => null],
    ];

    $deployment = dacEvaluate($deployment);

    expect($deployment->status)->toBe(DeploymentStatus::Queued)
        ->and($deployment->checks->state)->toBe(CheckGateState::Waiting)
        ->and(array_map(fn (CommitCheckData $check): array => [$check->name, $check->state], $deployment->checks->checks))->toBe([
            ['test', CommitCheckState::Pending],
            ['lint', CommitCheckState::Passed],
            ['ci/circleci: build', CommitCheckState::Passed],
        ])
        ->and($deployment->checks->checks[0]->url)->toBe('https://github.com/octo-org/web/runs/test');
    Queue::assertNotPushed(AdvanceDeploymentJob::class, fn (AdvanceDeploymentJob $job): bool => $job->deploymentId === $deployment->id);

    $this->runs[0] = dacRun('test');
    $deployment = dacEvaluate($deployment);

    expect($deployment->checks->state)->toBe(CheckGateState::Passed)
        ->and($deployment->waitsForChecks())->toBeFalse();
    Queue::assertPushed(AdvanceDeploymentJob::class, fn (AdvanceDeploymentJob $job): bool => $job->deploymentId === $deployment->id);

    resolve(AdvanceDeployment::class)->handle($deployment->id);
    expect($deployment->refresh()->status)->not->toBe(DeploymentStatus::Queued);
});

test('a failed check cancels it with the check’s name; the old version keeps serving', function (): void {
    $deployment = dacPush($this);
    $this->runs = [dacRun('test', 'completed', 'failure'), dacRun('e2e', 'in_progress', null)];

    $deployment = dacEvaluate($deployment);

    expect($deployment->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->failure_class)->toBeNull()
        ->and($deployment->failure_message)->toBe('Not deployed: the check “test” failed on 5555555.')
        ->and($deployment->checks->state)->toBe(CheckGateState::Failed)
        ->and(ServiceInstance::query()->where('service_id', $this->web->id)->value('active_deployment_id'))->not->toBe($deployment->id);
});

test('checks still running at the deadline cancel it', function (): void {
    $deployment = dacPush($this);
    $this->runs = [dacRun('e2e', 'in_progress', null)];

    $this->travel(31)->minutes();
    $deployment = dacEvaluate($deployment);

    expect($deployment->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->checks->state)->toBe(CheckGateState::TimedOut)
        ->and($deployment->failure_message)->toBe('Not deployed: checks were still running on 5555555 after 30 minutes (e2e).');
});

test('no checks at all: it waits through the grace period, then deploys without them', function (): void {
    $deployment = dacPush($this);

    expect(dacEvaluate($deployment)->checks->state)->toBe(CheckGateState::Waiting);

    $this->travel(config()->integer('launchroom.deployments.checks_grace_seconds') + 1)->seconds();

    expect(dacEvaluate($deployment)->checks->state)->toBe(CheckGateState::None);
    Queue::assertPushed(AdvanceDeploymentJob::class, fn (AdvanceDeploymentJob $job): bool => $job->deploymentId === $deployment->id);
});

test('GitHub not answering keeps it waiting (fail closed) and times out with the reason', function (): void {
    $deployment = dacPush($this);
    $this->githubDown = true;

    $this->travel(5)->minutes();
    $deployment = dacEvaluate($deployment);

    expect($deployment->status)->toBe(DeploymentStatus::Queued)
        ->and($deployment->checks->problem)->toBe('GitHub didn’t answer with the checks of this commit.');

    $this->travel(30)->minutes();
    $deployment = dacEvaluate($deployment);

    expect($deployment->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->checks->state)->toBe(CheckGateState::TimedOut)
        ->and($deployment->failure_message)->toStartWith('Not deployed: launchroom couldn’t read the checks of 5555555 for 30 minutes.');
});

test('a newer push supersedes the waiting one; manual deploys never wait', function (): void {
    $first = dacPush($this);
    $second = dacPush($this, DAC_NEXT);

    expect($first->refresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and($second->waitsForChecks())->toBeTrue();

    $instance = ServiceInstance::query()->where('service_id', $this->web->id)->sole();
    $manual = resolve(RedeployService::class)->handle($instance, null, null, AuditActor::system());

    expect($manual->checks)->toBeNull();
});

test('environments without the switch deploy pushes right away', function (): void {
    Environment::query()->whereKey($this->production->id)->update(['triggers' => json_encode(new EnvironmentTriggers(branch: 'main'))]);

    expect(dacPush($this)->checks)->toBeNull();
});

test('check webhooks re-evaluate the waiting deployments of that commit only', function (): void {
    $deployment = dacPush($this);
    Queue::fake();
    // Deliveries are processed on the queue: run each one that was queued.
    $process = function (): void {
        Queue::pushed(ProcessWebhookDeliveryJob::class)->each(fn (ProcessWebhookDeliveryJob $job) => app()->call($job->handle(...)));
        Queue::fake();
    };

    $payload = fn (string $sha): array => ['action' => 'completed', 'installation' => ['id' => 100], 'repository' => ['id' => 555, 'full_name' => 'octo-org/web'], 'check_suite' => ['head_sha' => $sha]];

    GitHubFake::webhook($this, $this->githubApp, 'check_suite', $payload(DAC_NEXT))->assertSuccessful();
    $process();
    Queue::assertNotPushed(EvaluateDeploymentChecksJob::class);

    GitHubFake::webhook($this, $this->githubApp, 'check_suite', $payload(DAC_SHA))->assertSuccessful();
    GitHubFake::webhook($this, $this->githubApp, 'status', ['sha' => DAC_SHA, 'state' => 'success', 'context' => 'ci', 'installation' => ['id' => 100], 'repository' => ['id' => 555]])->assertSuccessful();
    GitHubFake::webhook($this, $this->githubApp, 'check_run', ['action' => 'completed', 'installation' => ['id' => 100], 'repository' => ['id' => 555], 'check_run' => ['head_sha' => DAC_SHA]])->assertSuccessful();
    Queue::pushed(ProcessWebhookDeliveryJob::class)->each(fn (ProcessWebhookDeliveryJob $job) => app()->call($job->handle(...)));

    expect(WebhookDelivery::query()->where('event', 'check_suite')->pluck('status')->map->value->sort()->values()->all())->toBe(['ignored', 'processed']);
    Queue::assertPushed(EvaluateDeploymentChecksJob::class, fn (EvaluateDeploymentChecksJob $job): bool => $job->deploymentId === $deployment->id);
});

test('the minute poll evaluates every waiting deployment and nothing else', function (): void {
    $deployment = dacPush($this);
    Queue::fake();

    $this->artisan('launchroom:deployments:check-gates')->assertSuccessful();

    Queue::assertPushed(EvaluateDeploymentChecksJob::class, 1);
    Queue::assertPushed(EvaluateDeploymentChecksJob::class, fn (EvaluateDeploymentChecksJob $job): bool => $job->deploymentId === $deployment->id);
});

test('Deploy now: deployers only, while it waits, audited', function (TeamRole $role, bool $allowed): void {
    $deployment = dacPush($this);
    $args = [$this->project->public_id, $this->production->public_id, $deployment->public_id];

    $response = $this->actingAs(memberOf($this->team, $role))->post(route('deployments.skip-checks', $args));

    if (! $allowed) {
        $response->assertForbidden();
        expect($deployment->refresh()->waitsForChecks())->toBeTrue();

        return;
    }

    $response->assertRedirect();
    $deployment->refresh();

    expect($deployment->checks->state)->toBe(CheckGateState::Skipped)
        ->and($deployment->checks->skippedBy)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::DeploymentChecksSkipped->value)->where('subject_id', $deployment->public_id)->exists())->toBeTrue();
    Queue::assertPushed(AdvanceDeploymentJob::class, fn (AdvanceDeploymentJob $job): bool => $job->deploymentId === $deployment->id);

    // Not waiting anymore: nothing to skip.
    $this->post(route('deployments.skip-checks', $args))->assertForbidden();
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'developer' => [TeamRole::Developer, true],
    'viewer' => [TeamRole::Viewer, false],
]);

test('the API shows the gate and skips it with the deploy ability', function (): void {
    $deployment = dacPush($this);
    $owner = memberOf($this->team, TeamRole::Owner);

    Api::get("deployments/{$deployment->public_id}", Api::token($owner, $this->team, [ApiAbility::Read]))
        ->assertOk()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.checks.state', 'waiting')
        ->assertJsonPath('data.checks.checks', []);

    Api::call('POST', "deployments/{$deployment->public_id}/skip-checks", Api::token($owner, $this->team, [ApiAbility::Read]))
        ->assertForbidden();
    expect($deployment->refresh()->waitsForChecks())->toBeTrue();

    Api::call('POST', "deployments/{$deployment->public_id}/skip-checks", Api::token($owner, $this->team, [ApiAbility::Read, ApiAbility::Deploy]))
        ->assertOk()
        ->assertJsonPath('data.checks.state', 'skipped');
});

test('the environment keeps the switch and bounds its timeout', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $route = route('environments.update', [$this->project->public_id, $this->production->public_id]);

    $this->actingAs($owner)->patch($route, ['triggers' => ['branch' => 'main', 'waitForChecks' => true, 'checksTimeoutMinutes' => 241]])
        ->assertSessionHasErrors('triggers.checksTimeoutMinutes');

    $this->actingAs($owner)->patch($route, ['triggers' => ['branch' => 'main', 'waitForChecks' => true, 'checksTimeoutMinutes' => 60]])
        ->assertSessionHasNoErrors();

    $triggers = $this->production->refresh()->triggers;

    expect($triggers->waitForChecks)->toBeTrue()
        ->and($triggers->checksTimeoutMinutes)->toBe(60);
});

test('GitLab statuses: jobs allowed to fail pass, launchroom’s own status is left out', function (): void {
    GitProviderFake::setUp();
    $gitlab = GitProviderFake::connection($this->team, GitProviderKind::GitLab);
    GitProviderFake::repository($gitlab, 42, 'acme/web');
    Http::fake([GitProviderFake::GITLAB.'/api/v4/projects/42/repository/commits/'.DAC_SHA.'/statuses*' => Http::response([
        ['name' => 'test', 'status' => 'running', 'target_url' => GitProviderFake::GITLAB.'/acme/web/-/jobs/1', 'allow_failure' => false],
        ['name' => 'flaky', 'status' => 'failed', 'target_url' => null, 'allow_failure' => true],
        ['name' => 'deploy-docs', 'status' => 'manual', 'target_url' => null, 'allow_failure' => false],
        ['name' => 'launchroom: web (production)', 'status' => 'pending', 'target_url' => null, 'allow_failure' => false],
    ])]);

    $checks = resolve(GitLabProvider::class)->commitChecks(new RepositoryRef(GitProviderKind::GitLab, $this->team->id, $gitlab->id, 42, 'acme/web'), DAC_SHA);

    expect(array_map(fn (CommitCheckData $check): array => [$check->name, $check->state], $checks))->toBe([
        ['test', CommitCheckState::Pending],
        ['flaky', CommitCheckState::Passed],
        ['deploy-docs', CommitCheckState::Passed],
    ]);
});
