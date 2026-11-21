<?php

declare(strict_types=1);

use App\Actions\Alerts\RaiseAlert;
use App\Data\Alerts\AlertSignal;
use App\Data\Canvas\NodeStatusData;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\DeploymentStatus;
use App\Enums\NodeStatusReason;
use App\Enums\ServerStatus;
use App\Enums\StatusTone;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Queries\ProjectSummaries;
use App\Queries\TeamAttention;
use App\Support\Canvas\ServiceProblems;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

/** web's latest deployment failed and nothing of it is live. */
function failWeb(DeploymentUi $world): Deployment
{
    $deployment = $world->latest($world->web);
    Deployment::query()->whereKey($deployment->id)->update(['status' => DeploymentStatus::Failed->value]);
    ServiceInstance::query()->whereKey($deployment->service_instance_id)->update(['active_deployment_id' => null]);

    return $deployment;
}

test('a healthy team needs nothing and the attention list is deferred', function (): void {
    $world = DeploymentUi::make();
    $viewer = $world->member(TeamRole::Viewer);

    expect(resolve(TeamAttention::class)->for($world->team, $viewer, resolve(ProjectSummaries::class)->for($world->team)))->toBe([]);

    $this->actingAs($viewer)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/index')
            ->missing('attention')
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload->where('attention', [])->etc())
            ->etc());
});

test('a failed service is named on its card and links to why', function (): void {
    $world = DeploymentUi::make();
    $healthy = Projects::create($world->team, 'aaa');
    Projects::image($healthy, 'site');
    // Cards sort by recent activity: "aaa" was last touched before shop's deploys.
    Project::query()->whereKey($healthy->id)->update(['updated_at' => now()->subDays(30)]);
    $failed = failWeb($world);
    $href = route('deployments.show', $world->deploymentArgs($failed), absolute: false);

    $this->actingAs($world->member(TeamRole::Viewer))
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('projects.0.id', $world->project->public_id)
            ->where('projects.1.id', $healthy->public_id)
            ->where('projects.0.environments.0.status.state', 'failed')
            ->has('projects.0.environments.0.problems', 1)
            ->where('projects.0.environments.0.problems.0.serviceId', $world->web->public_id)
            ->where('projects.0.environments.0.problems.0.sentence', 'web failed to deploy')
            ->where('projects.0.environments.0.problems.0.href', $href)
            ->where('projects.1.environments.0.problems', [])
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->where('attention.0.kind', 'service')
                ->where('attention.0.state', 'failed')
                ->where('attention.0.title', 'web failed to deploy')
                ->where('attention.0.context', 'shop')
                ->where('attention.0.href', $href)
                ->where('attention.0.action', 'See why')
                ->etc())
            ->etc());
});

test('problems read as sentences and crashes link to the runtime logs', function (): void {
    expect(ServiceProblems::sentence('api', new NodeStatusData(StatusTone::Warning, NodeStatusReason::LastDeployFailed, 'Last deploy failed')))->toBe('The last deploy of api failed')
        ->and(ServiceProblems::sentence('api', new NodeStatusData(StatusTone::Failed, NodeStatusReason::Crashed, 'Crashed')))->toBe('api crashed')
        ->and(ServiceProblems::sentence('db', new NodeStatusData(StatusTone::Warning, NodeStatusReason::Paused, 'Paused: postgres is being imported', detail: 'postgres is being imported')))->toBe('db is paused: postgres is being imported');

    $crashed = ServiceProblems::for('prj_A', 'env_B', 'svc_C', 'api', new NodeStatusData(StatusTone::Failed, NodeStatusReason::Crashed, 'Crashed', null, 'dpl_D'));
    $live = ServiceProblems::for('prj_A', 'env_B', 'svc_C', 'api', new NodeStatusData(StatusTone::Active, NodeStatusReason::Live, 'Live'));

    expect($crashed?->href)->toEndWith('/s/svc_C/logs')
        ->and($live)->toBeNull();
});

test('servers that are down, staged changes and paused auto-deploy need you; other teams never show', function (): void {
    $world = DeploymentUi::make();
    $other = DeploymentUi::make();
    Server::query()->whereKey($world->server->id)->update(['status' => ServerStatus::Unreachable->value]);
    Server::query()->whereKey($other->server->id)->update(['status' => ServerStatus::Unreachable->value]);
    $world->variable('LOG_LEVEL', 'debug');
    $world->production->forceFill(['auto_deploy_paused' => true])->save();
    $viewer = $world->member(TeamRole::Viewer);

    $items = collect(resolve(TeamAttention::class)->for($world->team, $viewer, resolve(ProjectSummaries::class)->for($world->team)));

    expect($items->pluck('kind')->all())->toBe(['server', 'staged', 'paused'])
        ->and($items[0]->title)->toBe("{$world->server->name} is unreachable")
        ->and($items[0]->href)->toBe(route('servers.show', [$world->server->public_id], absolute: false))
        ->and($items[1]->title)->toBe('1 change is waiting to deploy')
        ->and($items[1]->href)->toBe(route('staged-changes.show', $world->envArgs(), absolute: false))
        ->and($items[2]->context)->toBe('shop')
        ->and(json_encode($items->all()))->not->toContain($other->server->public_id)
        // Never variable values.
        ->and(json_encode($items->all()))->not->toContain('debug');
});

test('failed and overdue backups need you, launchroom\'s own included, and link to where they are fixed', function (): void {
    $world = DeploymentUi::make();
    $other = DeploymentUi::make();
    $raise = resolve(RaiseAlert::class);
    $href = '/p/'.$world->project->public_id.'/'.$world->production->public_id.'/s/'.$world->postgres->public_id.'/settings/backups';
    $raise->handle(new AlertSignal(team: $world->team, rule: AlertRule::BackupFailed, subject: 'backup_overdue:'.$world->postgres->public_id,
        title: 'postgres hasn’t been backed up for 30 hours', summary: 'Overdue.', severity: AlertSeverity::Warning,
        href: $href, project: $world->project, environment: $world->production));
    $raise->handle(new AlertSignal(team: $world->team, rule: AlertRule::BackupFailed, subject: 'control_plane:backup',
        title: 'launchroom’s own backup failed', summary: 'Failed.', severity: AlertSeverity::Critical, href: route('backups.index', absolute: false)));
    $raise->handle(new AlertSignal(team: $other->team, rule: AlertRule::BackupFailed, subject: 'control_plane:backup',
        title: 'Another team’s backup failed', summary: 'Failed.'));
    // Other rules have their own rows (or none).
    $raise->handle(new AlertSignal(team: $world->team, rule: AlertRule::CpuHigh, subject: 'server:'.$world->server->public_id,
        title: 'CPU is high', summary: 'High.'));

    $items = collect(resolve(TeamAttention::class)->for($world->team, $world->member(TeamRole::Viewer), resolve(ProjectSummaries::class)->for($world->team)))
        ->where('kind', 'backup')->values();

    expect($items)->toHaveCount(2)
        ->and($items[0]->title)->toBe('launchroom’s own backup failed')
        ->and($items[0]->state)->toBe(StatusTone::Failed)
        ->and($items[0]->context)->toBeNull()
        ->and($items[0]->href)->toBe('/settings/backups')
        ->and($items[1]->state)->toBe(StatusTone::Warning)
        ->and($items[1]->context)->toBe('shop')
        ->and($items[1]->href)->toBe($href)
        ->and($items[1]->action)->toBe('See why');
});
