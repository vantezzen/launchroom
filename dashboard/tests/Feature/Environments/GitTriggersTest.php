<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Environments\UpdateEnvironment;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Environments\EnvironmentTriggers;
use App\Data\Environments\UpdateEnvironmentData;
use App\Data\Git\CommitData;
use App\Data\Services\CreateServiceData;
use App\Enums\DeploymentTrigger;
use App\Enums\Git\GitEventSource;
use App\Enums\ServiceKind;
use App\Events\Git\GitPushReceived;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Git\GitHubInstallation;
use App\Models\ServiceInstance;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitHubFake;
use Tests\Support\Projects;

const GT_SHA = '3333333333333333333333333333333333333333';

beforeEach(function (): void {
    Projects::useTestKek();
    Queue::fake();
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    Projects::server($this->team);
    $this->installation = GitHubFake::installation(GitHubFake::app($this->team));
    GitHubFake::repository($this->installation, 555, 'octo-org/web');
    Http::fake(['https://api.github.com/*' => Http::response(['id' => 1, 'sha' => GT_SHA], 201)]);

    $this->project = Projects::create($this->team);
    $this->web = resolve(CreateService::class)->handle($this->project, CreateServiceData::from([
        'name' => 'web', 'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'repositoryId' => 555, 'fullName' => 'octo-org/web', 'installationId' => $this->installation->id],
    ]), null, AuditActor::system());
    $this->production = $this->project->production()->sole();
    $this->staging = resolve(CreateEnvironment::class)->handle($this->project, new CreateEnvironmentData('staging', triggers: new EnvironmentTriggers(branch: 'develop')), null, AuditActor::system());
    $this->qa = resolve(CreateEnvironment::class)->handle($this->project, new CreateEnvironmentData('qa', triggers: new EnvironmentTriggers(branch: 'release/*')), null, AuditActor::system());

    foreach ([$this->production, $this->staging, $this->qa] as $environment) {
        resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());
    }
});

function gtPush(GitHubInstallation $installation, string $ref, ?array $files = null): void
{
    event(new GitPushReceived($installation, 555, $ref, str_repeat('0', 40), GT_SHA, new CommitData(GT_SHA, 'ship', 'Ada', 'ada@example.com', 'ada'), $files, false, GitEventSource::Webhook));
}

/**
 * @return list<string> names of the environments that got a deployment of the push
 */
function gtDeployedTo(): array
{
    /** @var list<string> $names */
    $names = Deployment::query()->where('commit_sha', GT_SHA)->with('environment')->get()->map(static fn (Deployment $deployment): string => $deployment->environment->name)->sort()->values()->all();
    Deployment::query()->where('commit_sha', GT_SHA)->delete();

    return $names;
}

test('branch pushes go to every environment whose branch glob matches, each with its own instance', function (): void {
    gtPush($this->installation, 'refs/heads/main');
    expect(gtDeployedTo())->toBe(['production']);

    gtPush($this->installation, 'refs/heads/develop');
    expect(gtDeployedTo())->toBe(['staging']);

    gtPush($this->installation, 'refs/heads/release/1.2');
    expect(gtDeployedTo())->toBe(['qa']);

    gtPush($this->installation, 'refs/heads/feature/x');
    expect(gtDeployedTo())->toBe([]);

    // Two environments tracking the same branch both deploy.
    resolve(UpdateEnvironment::class)->handle($this->qa, UpdateEnvironmentData::from(['triggers' => ['branch' => 'develop']]), AuditActor::system());
    gtPush($this->installation, 'refs/heads/develop');

    $deployments = Deployment::query()->where('commit_sha', GT_SHA)->get();

    expect($deployments->pluck('service_instance_id')->unique())->toHaveCount(2)
        ->and($deployments->pluck('trigger')->unique()->all())->toBe([DeploymentTrigger::Push]);
});

test('tag pushes deploy where the tag glob matches; production may track tags only', function (): void {
    resolve(UpdateEnvironment::class)->handle($this->production, UpdateEnvironmentData::from(['triggers' => ['branch' => null, 'tagPattern' => 'v*']]), AuditActor::system());

    gtPush($this->installation, 'refs/heads/main');
    expect(gtDeployedTo())->toBe([]);

    gtPush($this->installation, 'refs/tags/v1.4.0');
    $deployment = Deployment::query()->where('commit_sha', GT_SHA)->sole();

    expect($deployment->environment_id)->toBe($this->production->id)
        ->and($deployment->trigger)->toBe(DeploymentTrigger::Tag)
        ->and($deployment->git_ref)->toBe('refs/tags/v1.4.0');

    gtDeployedTo();
    gtPush($this->installation, 'refs/tags/nightly');
    expect(gtDeployedTo())->toBe([]);
});

test('manual-only environments, paused auto-deploy and watch paths are respected per environment', function (): void {
    resolve(UpdateEnvironment::class)->handle($this->staging, UpdateEnvironmentData::from(['triggers' => ['branch' => null, 'tagPattern' => null]]), AuditActor::system());
    gtPush($this->installation, 'refs/heads/develop');
    expect(gtDeployedTo())->toBe([]);

    Environment::query()->whereKey($this->production->id)->update(['auto_deploy_paused' => true]);
    gtPush($this->installation, 'refs/heads/main');
    expect(gtDeployedTo())->toBe([]);

    Environment::query()->whereKey($this->production->id)->update(['auto_deploy_paused' => false]);
    $instance = ServiceInstance::query()->where('environment_id', $this->qa->id)->where('service_id', $this->web->id)->sole();
    $instance->forceFill(['build' => [...$instance->build->toArray(), 'watchPaths' => ['apps/web']]])->save();

    gtPush($this->installation, 'refs/heads/release/2', ['docs/readme.md']);
    expect(gtDeployedTo())->toBe([]);

    gtPush($this->installation, 'refs/heads/release/2', ['apps/web/index.ts']);
    expect(gtDeployedTo())->toBe(['qa']);
});
