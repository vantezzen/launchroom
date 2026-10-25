<?php

declare(strict_types=1);

use App\Actions\Environments\CreateEnvironment;
use App\Actions\Environments\DeleteEnvironment;
use App\Actions\Projects\DeleteProject;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CopyEnvironmentOptions;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Environments\DeleteEnvironmentData;
use App\Data\Projects\DeleteProjectData;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Alerts\AlertStatus;
use App\Models\Alerts\Alert;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Models\Team;
use Illuminate\Contracts\Database\Query\Builder;
use Tests\Support\Backups;
use Tests\Support\Projects;

/*
| Deleting a project or environment with alerts on its services (docs/15
| §3.1): the delete used to fail with a foreign-key violation on
| alerts.service_instance_id (see App\Actions\Alerts\DetachAlerts). Firing
| alerts resolve with the reason; every alert keeps its history but loses
| its links to the deleted rows.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

/**
 * @param  array<string, mixed>  $columns
 */
function aodAlert(ServiceInstance $instance, array $columns, AlertStatus $status = AlertStatus::Firing): Alert
{
    $alert = new Alert([
        'team_id' => $instance->team_id,
        'rule' => $status === AlertStatus::Firing ? AlertRule::ServiceUnhealthy : AlertRule::DeployFailed,
        'subject_key' => 'svc:'.$instance->public_id,
        'severity' => AlertSeverity::Critical,
        'title' => 'postgres is unhealthy',
        'summary' => 'It keeps restarting.',
        'facts' => [],
        'opened_at' => now(),
        'last_seen_at' => now(),
        ...$columns,
    ]);
    $alert->save();

    if ($status === AlertStatus::Resolved) {
        $alert->transitionTo(AlertStatus::Resolved, 'It recovered.');
    }

    return $alert;
}

/**
 * A project whose Postgres instance is in $environment ("production" or a
 * second one), deployed or not (deployments and placements don't matter).
 *
 * @return array{Project, Environment, ServiceInstance}
 */
function aodProject(bool $deployed, string $environment = 'production'): array
{
    if ($deployed && $environment === 'production') {
        [, , , $instance] = Backups::runningPostgres();
    } else {
        $team = Team::factory()->create();
        Projects::server($team);
        $project = Projects::create($team, 'shop');
        $service = Projects::postgres($project);

        if ($environment !== 'production') {
            resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData($environment), null, AuditActor::system(), new CopyEnvironmentOptions);
        }

        $instance = ServiceInstance::query()->where('service_id', $service->id)->whereHas('environment', static fn (Builder $query) => $query->where('name', $environment))->sole();
    }

    $instance->load('environment.project');

    return [$instance->environment->project, $instance->environment, $instance];
}

test('a project with an alert on one of its services can be deleted; the alert resolves and keeps its history', function (bool $deployed, array $keys): void {
    [$project, $environment, $instance] = aodProject($deployed);
    $all = ['project_id' => $project->id, 'environment_id' => $environment->id, 'service_instance_id' => $instance->id];
    $alert = aodAlert($instance, array_intersect_key($all, array_flip($keys)));
    $past = aodAlert($instance, $all, AlertStatus::Resolved);

    resolve(DeleteProject::class)->handle($project, new DeleteProjectData($project->name, skipFinalBackup: true), AuditActor::system());

    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse()
        ->and($alert->refresh()->status)->toBe(AlertStatus::Resolved)
        ->and($alert->resolve_reason)->toBe('The project was deleted.')
        ->and($alert->resolved_at)->not->toBeNull()
        ->and([$alert->project_id, $alert->environment_id, $alert->service_instance_id])->toBe([null, null, null])
        ->and($alert->title)->toBe('postgres is unhealthy')
        // Past alerts keep how they ended.
        ->and($past->refresh()->resolve_reason)->toBe('It recovered.')
        ->and([$past->project_id, $past->environment_id, $past->service_instance_id])->toBe([null, null, null]);
})->with([
    'deployed' => true,
    'never deployed' => false,
])->with([
    // The minimal case that failed: the project and the instance on one alert.
    'project and instance' => [['project_id', 'service_instance_id']],
    'project, environment and instance' => [['project_id', 'environment_id', 'service_instance_id']],
    'environment and instance' => [['environment_id', 'service_instance_id']],
]);

test('an environment with an alert on one of its services can be deleted; the alert stays with the project', function (array $keys): void {
    [$project, $environment, $instance] = aodProject(false, 'staging');
    $all = ['project_id' => $project->id, 'environment_id' => $environment->id, 'service_instance_id' => $instance->id];
    $alert = aodAlert($instance, array_intersect_key($all, array_flip($keys)));
    $production = aodProject(false);
    $elsewhere = aodAlert($production[2], ['project_id' => $production[0]->id, 'environment_id' => $production[1]->id, 'service_instance_id' => $production[2]->id]);

    resolve(DeleteEnvironment::class)->handle($environment, new DeleteEnvironmentData('staging', skipFinalBackup: true), AuditActor::system());

    expect(Environment::query()->whereKey($environment->id)->exists())->toBeFalse()
        ->and($alert->refresh()->status)->toBe(AlertStatus::Resolved)
        ->and($alert->resolve_reason)->toBe('The environment was deleted.')
        ->and([$alert->environment_id, $alert->service_instance_id])->toBe([null, null])
        ->and($alert->project_id)->toBe(in_array('project_id', $keys, true) ? $project->id : null)
        // Other projects' alerts are untouched.
        ->and($elsewhere->refresh()->status)->toBe(AlertStatus::Firing)
        ->and($elsewhere->service_instance_id)->toBe($production[2]->id);
})->with([
    'project and instance' => [['project_id', 'service_instance_id']],
    'project, environment and instance' => [['project_id', 'environment_id', 'service_instance_id']],
    'environment and instance' => [['environment_id', 'service_instance_id']],
]);
