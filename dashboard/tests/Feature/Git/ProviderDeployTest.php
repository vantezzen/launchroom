<?php

declare(strict_types=1);

use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Git\PollConnectionRef;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Git\CommitData;
use App\Data\Git\DeploymentStatusReport;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Services\CreateServiceData;
use App\Enums\DaemonFeature;
use App\Enums\Git\DeploymentReportState;
use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitEventSource;
use App\Enums\Git\GitProviderKind;
use App\Enums\ServiceKind;
use App\Events\Git\GitPushReceived;
use App\Jobs\Git\ReportDeploymentStatusJob;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitPullRequestComment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\GitConnectionTokens;
use App\Services\Git\GitProviders;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitHubFake;
use Tests\Support\GitProviderFake;
use Tests\Support\Projects;

/*
| GitLab / Gitea / plain git sources through the build pipeline (docs/19 §6):
| head lookup, clone credentials sealed at lease time, SSH host keys, the
| daemon feature gate, provider-scoped push matching, feedback and polling.
*/

const PD_SHA_1 = '1111111111111111111111111111111111111111';
const PD_SHA_2 = '2222222222222222222222222222222222222222';

beforeEach(function (): void {
    GitProviderFake::setUp();
    $this->team = Team::factory()->create();
    [$this->server, $this->keypair] = Projects::server($this->team);
    $this->server->forceFill(['daemon_features' => [DaemonFeature::GitProviders->value]])->save();
    $this->gitlab = GitProviderFake::connection($this->team, GitProviderKind::GitLab);
    $this->repository = GitProviderFake::repository($this->gitlab, 42, 'acme/platform/web');
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();

    $this->head = PD_SHA_1;
    Http::fake([
        GitProviderFake::GITLAB.'/api/v4/projects/42/repository/branches/main' => fn () => Http::response(['name' => 'main', 'commit' => ['id' => $this->head, 'message' => 'next', 'author_name' => 'Ada']]),
    ]);
});

function gpdService(object $test, array $source, string $name = 'web'): Service
{
    return resolve(CreateService::class)->handle($test->project, CreateServiceData::from([
        'name' => $name, 'kind' => ServiceKind::App, 'source' => ['type' => 'git', ...$source],
    ]), null, AuditActor::system());
}

function gpdLease(object $test): array
{
    return resolve(LeaseTasks::class)->handle($test->server, LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['build']]));
}

function gpdOpen(object $test, string $sealed): array
{
    return json_decode((string) sodium_crypto_box_seal_open((string) base64_decode($sealed, true), $test->keypair), true);
}

test('GitLab sources resolve the head through the API and clone with the connection token, sealed at lease', function (): void {
    gpdService($this, $this->repository->gitSource());
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $deployment = Deployment::query()->latest('id')->firstOrFail();
    $task = DaemonTask::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->commit_sha)->toBe(PD_SHA_1)
        ->and($task->spec->repo->provider)->toBe('gitlab')
        ->and($task->spec->repo->cloneUrl)->toBe(GitProviderFake::GITLAB.'/acme/platform/web.git')
        ->and(json_encode($task))->not->toContain(GitProviderFake::TOKEN);

    $leased = gpdLease($this);

    expect(gpdOpen($this, $leased[0]->spec->cloneToken->sealed))
        ->toBe(['serverId' => $this->server->public_id, 'taskId' => $task->public_id, 'purpose' => 'clone', 'token' => GitProviderFake::TOKEN]);
});

test('builds that need the gitProviders feature are not leased to an older launchroomd', function (): void {
    gpdService($this, $this->repository->gitSource());
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $this->server->forceFill(['daemon_features' => []])->save();

    expect(gpdLease($this))->toBe([]);

    $this->server->forceFill(['daemon_features' => [DaemonFeature::GitProviders->value]])->save();
    expect(gpdLease($this))->toHaveCount(1);
});

test('SSH sources seal the deploy key seed and pin the confirmed host keys', function (): void {
    GitProviderFake::ssh([], ['HEAD' => PD_SHA_2, 'refs/heads/main' => PD_SHA_2]);
    $plain = GitProviderFake::connection($this->team, GitProviderKind::Git);
    $repository = GitProviderFake::repository($plain);
    gpdService($this, $repository->gitSource(branch: 'main'));

    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $deployment = Deployment::query()->latest('id')->firstOrFail();
    $task = DaemonTask::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->commit_sha)->toBe(PD_SHA_2)
        ->and($task->spec->repo->provider)->toBe('git')
        ->and($task->spec->repo->cloneUrl)->toBe('ssh://git@git.example.com/srv/app.git')
        ->and($task->spec->repo->ref)->toBe('refs/heads/main');

    $leased = gpdLease($this);
    $spec = $leased[0]->spec;
    $seed = gpdOpen($this, $spec->sshKey->sealed);

    expect($seed['purpose'])->toBe('ssh_key')
        ->and(strlen((string) base64_decode($seed['token'], true)))->toBe(32)
        ->and($spec->repo->knownHosts)->toBe([['type' => 'ssh-ed25519', 'key' => GitProviderFake::hostKey()->key]])
        ->and(property_exists($spec, 'cloneToken'))->toBeFalse();
});

test('an SSH source whose host keys are not confirmed fails at lease time instead of trusting any key', function (): void {
    GitProviderFake::ssh([], ['refs/heads/main' => PD_SHA_2]);
    $plain = GitProviderFake::connection($this->team, GitProviderKind::Git);
    gpdService($this, GitProviderFake::repository($plain)->gitSource(branch: 'main'));
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $plain->forceFill(['host_keys_confirmed_at' => null])->save();

    expect(gpdLease($this))->toBe([])
        ->and(DaemonTask::query()->latest('id')->firstOrFail()->result['failure']['class'] ?? null)->toBe('clone_error');
});

test('a push matches services of the same provider and connection only', function (): void {
    $service = gpdService($this, $this->repository->gitSource());
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    $count = static fn (): int => Deployment::query()->whereIn('service_instance_id', ServiceInstance::query()->select('id')->where('service_id', $service->id))->count();
    $before = $count();

    // A GitHub installation whose row id and repository id collide with the connection's.
    $installation = GitHubFake::installation(GitHubFake::app($this->team));
    GitHubInstallation::query()->whereKey($installation->id)->update(['id' => $this->gitlab->id + 5000]);
    GitHubInstallation::query()->whereKey($this->gitlab->id + 5000)->update(['id' => $this->gitlab->id]);
    $collision = GitHubInstallation::query()->whereKey($this->gitlab->id)->sole();
    $commit = new CommitData(PD_SHA_2, 'ship', 'Ada', null, 'ada');

    event(new GitPushReceived($collision, 42, 'refs/heads/main', PD_SHA_1, PD_SHA_2, $commit, null, false, GitEventSource::Webhook));
    expect($count())->toBe($before);

    // Another team's GitLab connection with the same project id.
    $other = GitProviderFake::connection(Team::factory()->create(), GitProviderKind::GitLab, accountId: 8);
    event(new GitPushReceived($other, 42, 'refs/heads/main', PD_SHA_1, PD_SHA_2, $commit, null, false, GitEventSource::Webhook));
    expect($count())->toBe($before);

    event(new GitPushReceived($this->gitlab, 42, 'refs/heads/main', PD_SHA_1, PD_SHA_2, $commit, null, false, GitEventSource::Webhook));
    expect($count())->toBe($before + 1)
        ->and(Deployment::query()->latest('id')->firstOrFail()->commit_sha)->toBe(PD_SHA_2);
});

test('deployment feedback becomes a GitLab commit status and, for previews, one MR note edited in place', function (): void {
    $report = fn (DeploymentReportState $state): DeploymentStatusReport => new DeploymentStatusReport(
        teamId: $this->team->id, installationId: $this->gitlab->id, repositoryId: 42, deploymentKey: 'dep_1', serviceName: 'web',
        environmentName: 'preview/pr-7', productionEnvironment: false, transientEnvironment: true, commitSha: PD_SHA_2, state: $state,
        deploymentUrl: 'https://launchroom.test/d/1', environmentUrl: 'https://web-pr-7.example.com', pullRequestNumber: 7, deploymentApi: false, provider: 'gitlab',
    );

    Http::fake([
        GitProviderFake::GITLAB.'/api/v4/projects/42/statuses/*' => Http::response(['id' => 1], 201),
        GitProviderFake::GITLAB.'/api/v4/projects/42/merge_requests/7/notes' => Http::response(['id' => 555], 201),
        GitProviderFake::GITLAB.'/api/v4/projects/42/merge_requests/7/notes/555' => Http::response(['id' => 555]),
    ]);

    new ReportDeploymentStatusJob($report(DeploymentReportState::InProgress))->handle(resolve(GitProviders::class));
    new ReportDeploymentStatusJob($report(DeploymentReportState::Success))->handle(resolve(GitProviders::class));

    Http::assertSent(fn (Request $request): bool => $request->url() === GitProviderFake::GITLAB.'/api/v4/projects/42/statuses/'.PD_SHA_2
        && $request['state'] === 'running' && $request['name'] === 'launchroom: web (preview/pr-7)'
        && $request->header('Authorization')[0] === 'Bearer '.GitProviderFake::TOKEN);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/statuses/') && $request['state'] === 'success');
    Http::assertSentCount(4); // two statuses, one note created, then edited
    expect(GitPullRequestComment::query()->sole()->comment_id)->toBe(555);
});

test('Gitea statuses use Gitea’s state names and contexts', function (): void {
    $gitea = GitProviderFake::connection($this->team, GitProviderKind::Gitea);
    GitProviderFake::repository($gitea, 11, 'acme/api');
    Http::fake([GitProviderFake::GITEA.'/api/v1/repos/acme/api/statuses/*' => Http::response(['id' => 1], 201)]);

    new ReportDeploymentStatusJob(new DeploymentStatusReport(
        teamId: $this->team->id, installationId: $gitea->id, repositoryId: 11, deploymentKey: 'dep_2', serviceName: 'api',
        environmentName: 'production', productionEnvironment: true, transientEnvironment: false, commitSha: PD_SHA_2,
        state: DeploymentReportState::Failure, deploymentUrl: 'https://launchroom.test/d/2', description: 'Build failed', provider: 'gitea',
    ))->handle(resolve(GitProviders::class));

    Http::assertSent(fn (Request $request): bool => $request['state'] === 'failure' && $request['context'] === 'launchroom: api (production)' && $request['description'] === 'Build failed');
});

test('reporting never throws and never reaches another team’s connection', function (): void {
    Http::fake([GitProviderFake::GITLAB.'/*' => Http::response([], 500)]);
    $other = Team::factory()->create();

    new ReportDeploymentStatusJob(new DeploymentStatusReport(
        teamId: $other->id, installationId: $this->gitlab->id, repositoryId: 42, deploymentKey: 'dep_3', serviceName: 'web',
        environmentName: 'production', productionEnvironment: true, transientEnvironment: false, commitSha: PD_SHA_2,
        state: DeploymentReportState::Success, deploymentUrl: 'https://launchroom.test/d/3', provider: 'gitlab',
    ))->handle(resolve(GitProviders::class));

    Http::assertNothingSent();
});

test('polling records a baseline, then emits a push when the branch moves', function (): void {
    Event::fake([GitPushReceived::class]);
    $poll = resolve(PollConnectionRef::class);

    expect($poll->handle($this->gitlab, 42, 'refs/heads/main'))->toBeFalse();

    $this->head = PD_SHA_2;

    expect($poll->handle($this->gitlab, 42, 'refs/heads/main'))->toBeTrue();
    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->installation->is($this->gitlab) && $event->after === PD_SHA_2 && $event->before === PD_SHA_1 && $event->source === GitEventSource::Polling);
});

test('a refused OAuth refresh marks the connection as needing a new sign-in and builds fail with a clear reason', function (): void {
    $oauth = GitProviderFake::connection($this->team, GitProviderKind::GitLab, method: GitAuthMethod::OAuth, accountId: 11);
    $oauth->forceFill(['token_expires_at' => now()->subMinute()])->save();
    Http::fake([GitProviderFake::GITLAB.'/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(fn () => resolve(GitConnectionTokens::class)->accessToken($oauth))
        ->toThrow(GitConnectionUnusable::class, 'Sign in to');
    expect($oauth->refresh()->status)->toBe(GitConnectionStatus::NeedsReauth);
});

test('an expiring OAuth token is refreshed once and the rotated refresh token stored encrypted', function (): void {
    $oauth = GitProviderFake::connection($this->team, GitProviderKind::GitLab, method: GitAuthMethod::OAuth, accountId: 12);
    $oauth->forceFill(['token_expires_at' => now()->addMinute()])->save();
    Http::fake([GitProviderFake::GITLAB.'/oauth/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'refresh-2', 'expires_in' => 7200])]);

    $tokens = resolve(GitConnectionTokens::class);

    expect($tokens->accessToken($oauth))->toBe('new-access')
        ->and($tokens->accessToken($oauth->refresh()))->toBe('new-access');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'refresh-1' && $request['client_secret'] === 'client-secret');

    $raw = GitConnection::query()->whereKey($oauth->id)->toBase()->first();
    expect(json_encode($raw))->not->toContain('refresh-2')->not->toContain('new-access');
});
