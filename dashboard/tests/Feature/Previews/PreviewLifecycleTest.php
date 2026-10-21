<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Previews\AdvancePreviews;
use App\Actions\Previews\ExpirePreviews;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Services\BuildSettings;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\EnvironmentKind;
use App\Enums\Previews\PreviewStatus;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Previews\PreviewShareLink;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Volume;
use Illuminate\Contracts\Database\Query\Builder;
use Tests\Support\PreviewFlow;
use Tests\Support\Projects;

beforeEach(function (): void {
    PreviewFlow::setUp();
});

function pvDeployment(Environment $environment, Service $service): ?Deployment
{
    return Deployment::query()
        ->where('environment_id', $environment->id)
        ->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))
        ->latest('id')
        ->first();
}

test('an opened pull request gets a preview copied from the base: databases first, then the apps at the PR head', function (): void {
    [, $server, , $project, $production, $postgres, $web] = PreviewFlow::project();

    $preview = PreviewFlow::event($project);
    $environment = $preview->environment;

    expect($environment)->toBeInstanceOf(Environment::class)
        ->and($environment->kind)->toBe(EnvironmentKind::Preview)
        ->and($environment->slug)->toBe('pr-7')
        ->and($environment->name)->toBe('PR #7')
        ->and($environment->base_environment_id)->toBe($production->id)
        ->and($environment->pr_number)->toBe(7)
        ->and($environment->pr_head_ref)->toBe('feature/login')
        ->and($preview->status)->toBe(PreviewStatus::Preparing)
        // Its own (new, empty) database.
        ->and(Volume::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('environment_id', $environment->id))->count())->toBe(1)
        ->and(pvDeployment($environment, $postgres)?->trigger)->toBe(DeploymentTrigger::PullRequest)
        // Apps wait for the database.
        ->and(pvDeployment($environment, $web))->toBeNull()
        ->and(Domain::query()->where('hostname', 'web-pr-7.shop.localhost')->exists())->toBeTrue()
        ->and(Domain::query()->where('hostname', 'web-git-feature-login.shop.localhost')->value('redirect_to'))->toBe('web-pr-7.shop.localhost');

    PreviewFlow::run($server);
    $preview->refresh();
    $app = pvDeployment($environment, $web);

    expect($preview->status)->toBe(PreviewStatus::Running)
        ->and($app?->commit_sha)->toBe(PreviewFlow::SHA_PR_1)
        ->and($app?->git_ref)->toBe('refs/heads/feature/login')
        ->and($app?->status)->toBe(DeploymentStatus::Active)
        ->and(pvDeployment($environment, $postgres)?->status)->toBe(DeploymentStatus::Active)
        ->and(ServiceInstance::query()->where('environment_id', $environment->id)->where('service_id', $web->id)->sole()->scaling->replicas)->toBe(1);

    // References resolve to the preview's own database.
    $placement = Placement::query()->where('deployment_id', $app?->id)->sole();
    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('id', $placement->workload_id);

    expect($workload['networks'])->toBe([['network' => $environment->networkId(), 'aliases' => ['web']]]);
});

test('staging is the default base when it exists', function (): void {
    [, $server, , $project] = PreviewFlow::project();
    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());
    resolve(DeployEnvironment::class)->handle($staging, null, AuditActor::system());
    PreviewFlow::run($server);

    $preview = PreviewFlow::event($project);

    expect($preview->environment?->base_environment_id)->toBe($staging->id);
});

test('filters skip previews: disabled, target branch, label, [skip preview]', function (array $settings, array $event, string $reason): void {
    [, , , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill($settings)->save();

    $preview = PreviewFlow::event($project, overrides: $event);

    expect($preview->status)->toBe(PreviewStatus::Skipped)
        ->and($preview->status_reason)->toContain($reason)
        ->and($preview->environment_id)->toBeNull()
        ->and(Environment::query()->where('kind', 'preview')->count())->toBe(0);
})->with([
    'disabled' => [['enabled' => false], [], 'off'],
    'target branch' => [['target_branches' => 'release/*, develop'], [], 'into release/*'],
    'label' => [['required_label' => 'preview'], ['labels' => ['bug']], 'labelled'],
    'title' => [[], ['title' => 'WIP [Skip Preview]'], 'skip'],
]);

test('removing the required label tears the preview down; adding it back starts it again', function (): void {
    [, , , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['required_label' => 'preview'])->save();

    $preview = PreviewFlow::event($project, overrides: ['labels' => ['preview']]);
    $environmentId = $preview->environment_id;
    expect($environmentId)->not->toBeNull();

    $preview = PreviewFlow::event($project, 'unlabeled', ['labels' => []]);
    expect($preview->status)->toBe(PreviewStatus::Skipped)
        ->and($preview->environment_id)->toBeNull()
        ->and(Environment::query()->whereKey($environmentId)->exists())->toBeFalse();

    $preview = PreviewFlow::event($project, 'labeled', ['labels' => ['preview'], 'label' => 'preview']);
    expect($preview->environment_id)->not->toBeNull();
});

test('max concurrent previews queue; a closed preview frees its slot for the oldest queued one', function (): void {
    [, , , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['max_concurrent' => 1])->save();

    $first = PreviewFlow::event($project, overrides: ['number' => 1]);
    $second = PreviewFlow::event($project, overrides: ['number' => 2, 'headRef' => 'feature/two']);

    expect($first->environment_id)->not->toBeNull()
        ->and($second->status)->toBe(PreviewStatus::Queued)
        ->and($second->status_reason)->toContain('free preview slot')
        ->and($second->environment_id)->toBeNull();

    PreviewFlow::event($project, 'closed', ['number' => 1]);

    expect($first->refresh()->status)->toBe(PreviewStatus::Closed)
        ->and($second->refresh()->environment_id)->not->toBeNull()
        ->and($second->status)->not->toBe(PreviewStatus::Queued);
});

test('focused previews: unchanged services deploy the base commit and reuse its image, changed ones build the head', function (): void {
    [, $server, , $project, $production, , $web] = PreviewFlow::project();
    $api = PreviewFlow::gitApp($project, 'api');
    foreach ([$web, $api] as $service) {
        $instance = ServiceInstance::query()->where('service_id', $service->id)->where('environment_id', $production->id)->sole();
        $instance->forceFill(['build' => new BuildSettings(watchPaths: [$service->slug === 'web' ? 'apps/web' : 'apps/api'])])->save();
    }
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    PreviewFlow::run($server);
    $baseApi = pvDeployment($production, $api);
    $builds = DaemonTask::query()->where('type', 'build')->count();

    $preview = PreviewFlow::event($project, overrides: ['changedFiles' => ['apps/web/index.js', 'README.md']]);
    PreviewFlow::run($server);
    $environment = $preview->environment;

    expect(pvDeployment($environment, $web)?->commit_sha)->toBe(PreviewFlow::SHA_PR_1)
        ->and(pvDeployment($environment, $api)?->commit_sha)->toBe($baseApi?->commit_sha)
        ->and(pvDeployment($environment, $api)?->image)->toBe($baseApi?->image)
        ->and(pvDeployment($environment, $api)?->plan['reusedFrom'] ?? null)->toBe($baseApi?->public_id)
        // Only web was built.
        ->and(DaemonTask::query()->where('type', 'build')->count())->toBe($builds + 1);

    // A push that makes the PR touch api too (the PR's whole diff counts).
    PreviewFlow::event($project, 'synchronize', ['headSha' => PreviewFlow::SHA_PR_2, 'changedFiles' => ['apps/web/index.js', 'apps/api/x.ts']]);
    PreviewFlow::run($server);

    expect(pvDeployment($environment, $api)?->commit_sha)->toBe(PreviewFlow::SHA_PR_2)
        ->and(pvDeployment($environment, $web)?->commit_sha)->toBe(PreviewFlow::SHA_PR_2)
        ->and(pvDeployment($environment, $api)?->status)->toBe(DeploymentStatus::Active)
        ->and($environment->refresh()->pr_head_sha)->toBe(PreviewFlow::SHA_PR_2);
});

test('unknown changed files rebuild every service at the head; a new head redeploys', function (): void {
    [, $server, , $project, , , $web] = PreviewFlow::project();
    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);

    PreviewFlow::event($project, 'synchronize', ['headSha' => PreviewFlow::SHA_PR_2]);
    PreviewFlow::run($server);

    expect(pvDeployment($preview->environment, $web)?->commit_sha)->toBe(PreviewFlow::SHA_PR_2)
        ->and(pvDeployment($preview->environment, $web)?->status)->toBe(DeploymentStatus::Active)
        ->and($preview->refresh()->status)->toBe(PreviewStatus::Running);

    // The same head again (e.g. an "edited" event) deploys nothing.
    $count = Deployment::query()->count();
    PreviewFlow::event($project, 'edited', ['headSha' => PreviewFlow::SHA_PR_2, 'title' => 'Renamed']);
    expect(Deployment::query()->count())->toBe($count)
        ->and($preview->refresh()->title)->toBe('Renamed');
});

test('closing the pull request tears the preview down through desired state', function (): void {
    [$team, $server, , $project] = PreviewFlow::project();
    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);
    $environment = $preview->environment;
    $link = PreviewShareLink::query()->create(['team_id' => $team->id, 'preview_id' => $preview->id, 'expires_at' => now()->addDay()]);
    $workloads = Placement::query()->whereIn('service_instance_id', ServiceInstance::query()->select('id')->where('environment_id', $environment->id))->pluck('workload_id')->all();

    PreviewFlow::event($project, 'closed', ['merged' => true]);
    $state = Projects::nodeState($server);

    expect($preview->refresh()->status)->toBe(PreviewStatus::Closed)
        ->and($preview->status_reason)->toContain('merged')
        ->and($preview->environment_id)->toBeNull()
        ->and($preview->closed_at)->not->toBeNull()
        ->and(Environment::query()->whereKey($environment->id)->exists())->toBeFalse()
        ->and(array_intersect(collect($state['workloads'])->pluck('id')->all(), $workloads))->toBe([])
        ->and(collect($state['routes'])->pluck('hosts')->flatten()->filter(static fn (string $host): bool => str_contains($host, 'pr-7'))->all())->toBe([])
        ->and($link->refresh()->revoked_at)->not->toBeNull();

    // Reopening starts over.
    $preview = PreviewFlow::event($project, 'reopened');
    expect($preview->environment_id)->not->toBeNull()
        ->and($preview->status)->toBe(PreviewStatus::Preparing);
});

test('previews expire after the configured days without activity', function (): void {
    [, , , $project] = PreviewFlow::project();
    PreviewSettings::forProject($project)->forceFill(['expiry_days' => 3])->save();
    $preview = PreviewFlow::event($project);

    Preview::query()->whereKey($preview->id)->update(['last_activity_at' => now()->subDays(2)]);
    expect(resolve(ExpirePreviews::class)->handle())->toBe(0);

    Preview::query()->whereKey($preview->id)->update(['last_activity_at' => now()->subDays(4)]);
    expect(resolve(ExpirePreviews::class)->handle())->toBe(1)
        ->and($preview->refresh()->status)->toBe(PreviewStatus::Expired)
        ->and($preview->environment_id)->toBeNull();

    // A new push brings it back.
    $preview = PreviewFlow::event($project, 'synchronize', ['headSha' => PreviewFlow::SHA_PR_2]);
    expect($preview->environment_id)->not->toBeNull();
});

test('the seed command runs once after the first deploy of the preview app', function (): void {
    [, $server, , $project, $production, , $web] = PreviewFlow::project();
    $instance = ServiceInstance::query()->where('service_id', $web->id)->where('environment_id', $production->id)->sole();
    $instance->forceFill(['deploy' => [...$instance->deploy->toArray(), 'seedCommand' => ['npm', 'run', 'seed']]])->save();
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    PreviewFlow::run($server);

    $preview = PreviewFlow::event($project);
    PreviewFlow::run($server);

    $seeds = DaemonTask::query()->where('purpose', 'seed')->whereIn('deployment_id', Deployment::query()->select('id')->where('environment_id', $preview->environment_id))->get();

    expect($seeds)->toHaveCount(1)
        ->and($seeds[0]->spec->args ?? $seeds[0]->spec->command)->toContain('seed');
});

test('a database that keeps failing to start fails the preview after three attempts', function (): void {
    [, $server, , $project, , $postgres] = PreviewFlow::project();
    $preview = PreviewFlow::event($project);

    for ($i = 0; $i < 3; $i++) {
        $placement = Placement::query()->whereIn('deployment_id', Deployment::query()->select('id')->where('environment_id', $preview->environment_id))->latest('id')->sole();
        Projects::report($server, phases: [$placement->workload_id => 'failed'], messages: [$placement->workload_id => 'network not found']);
        resolve(AdvancePreviews::class)->handle();
    }

    expect($preview->refresh()->status)->toBe(PreviewStatus::Failed)
        ->and($preview->status_reason)->toContain('postgres did not start')
        ->and(Deployment::query()->where('environment_id', $preview->environment_id)->where('status', 'failed')->count())->toBe(3)
        ->and($postgres->id)->toBeInt();
});
