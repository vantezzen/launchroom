<?php

declare(strict_types=1);

use App\Actions\Git\DeployPush;
use App\Data\Environments\EnvironmentTriggers;
use App\Data\Git\CommitData;
use App\Data\Git\TrackedRef;
use App\Data\Services\GitSource;
use App\Enums\Git\GitEventSource;
use App\Events\Git\GitPushReceived;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Services\Git\ServiceTrackedRefs;
use App\Support\Git\DeployBranch;
use Tests\Support\GitHubFake;
use Tests\Support\Projects;

/*
 * Which branch an instance deploys (docs/06 §2): a manual deploy falls back to
 * the source's own branch, pushes only follow the override or the
 * environment's branch, and polling watches exactly what pushes deploy.
 */

beforeEach(function (): void {
    Projects::useTestKek();
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    $this->installation = GitHubFake::installation(GitHubFake::app($this->team));
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->web = Projects::image($this->project, 'web');
    $this->web->forceFill(['kind' => 'app', 'source' => new GitSource(555, 'acme/shop', $this->installation->id, branch: 'develop')])->save();
});

function dbInstance(Service $service, Environment $environment): ServiceInstance
{
    return ServiceInstance::query()->where('service_id', $service->id)->where('environment_id', $environment->id)->sole()->load('environment');
}

/**
 * @return list<string>
 */
function dbTrackedRefs(): array
{
    return array_map(static fn (TrackedRef $ref): string => $ref->ref, [...resolve(ServiceTrackedRefs::class)->all()]);
}

test('a manual deploy builds the override, the environment branch, then the source branch, skipping patterns', function (): void {
    $source = $this->web->source;
    assert($source instanceof GitSource);
    $instance = dbInstance($this->web, $this->production);

    expect(DeployBranch::for($instance, $source))->toBe('main');

    $instance->branch_override = 'feature/login';
    expect(DeployBranch::for($instance, $source))->toBe('feature/login');

    $instance->branch_override = 'feature/*';
    expect(DeployBranch::for($instance, $source))->toBe('main');

    $this->production->forceFill(['triggers' => new EnvironmentTriggers(branch: 'release/*')])->save();
    expect(DeployBranch::for(dbInstance($this->web, $this->production), $source))->toBe('develop');

    $this->production->forceFill(['triggers' => new EnvironmentTriggers(tagPattern: 'v*')])->save();
    expect(DeployBranch::for(dbInstance($this->web, $this->production), $source))->toBe('develop')
        ->and(DeployBranch::pushPattern(dbInstance($this->web, $this->production)))->toBeNull();
});

test('polling watches the branch pushes deploy from, never the source branch alone', function (): void {
    expect(dbTrackedRefs())->toBe(['refs/heads/main']);

    ServiceInstance::query()->where('service_id', $this->web->id)->update(['branch_override' => 'feature/login']);
    expect(dbTrackedRefs())->toBe(['refs/heads/feature/login']);

    // Manual only (tags): branch pushes never deploy it, so no branch is polled,
    // although a manual deploy would build the source's `develop`.
    ServiceInstance::query()->where('service_id', $this->web->id)->update(['branch_override' => null]);
    $this->production->forceFill(['triggers' => new EnvironmentTriggers(tagPattern: 'v*')])->save();
    expect(dbTrackedRefs())->toBe([]);

    $push = new GitPushReceived($this->installation, 555, 'refs/heads/develop', str_repeat('a', 40), str_repeat('b', 40), new CommitData(str_repeat('b', 40), 'Ship it', null, null, null), null, false, GitEventSource::Polling);
    resolve(DeployPush::class)->handle($push);
    expect(Deployment::query()->count())->toBe(0);
});
