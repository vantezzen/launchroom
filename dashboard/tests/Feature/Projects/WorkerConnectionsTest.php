<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Connections\DeleteConnection;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Canvas\CanvasEdgeData;
use App\Data\Services\CreateServiceData;
use App\Data\Variables\VariableRowData;
use App\Enums\ChangeEffect;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Queries\CanvasGraph;
use App\Queries\CurrentEnvironmentSpec;
use App\Queries\ServiceVariablesView;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Changes\SpecDiff;
use Tests\Support\Projects;

/*
| Workers and crons run their app's code, so they get what the app's
| connections inject (DATABASE_URL and friends), keep getting it when the
| app's connections change, and show it on the canvas and in Variables.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
});

/**
 * @return array{Team, Project, Environment, Service, Service, Service}
 */
function wcProject(): array
{
    $team = Team::factory()->create();
    $project = Projects::create($team);
    $app = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'api',
        'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
    ]), null, AuditActor::system());
    $worker = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'queue', 'kind' => ServiceKind::Worker, 'parentServiceId' => $app->public_id, 'startCommand' => ['node', 'worker.js']]), null, AuditActor::system());
    $postgres = Projects::postgres($project);

    return [$team, $project, $project->production()->sole(), $app, $worker, $postgres];
}

/**
 * @return array<string, string>
 */
function wcEnv(Project $project, Environment $environment, Service $service): array
{
    $spec = resolve(CurrentEnvironmentSpec::class)->get($environment);

    return resolve(SpecVariableResolver::class)->resolve($project, $spec, $spec->services[$service->public_id])->all();
}

test('a worker gets the connection variables of its app', function (): void {
    [, $project, $production, $app, $worker, $postgres] = wcProject();
    resolve(CreateConnection::class)->handle($app, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());

    $appEnv = wcEnv($project, $production, $app);
    $workerEnv = wcEnv($project, $production, $worker);

    expect($workerEnv['DATABASE_URL'])->toStartWith('postgresql://app:')
        ->and($workerEnv['DATABASE_URL'])->toBe($appEnv['DATABASE_URL'])
        ->and($workerEnv['LAUNCHROOM_SERVICE'])->toBe('queue');

    $envs = resolve(SpecVariableResolver::class)->resolveAll($project, resolve(CurrentEnvironmentSpec::class)->get($production));
    expect($envs[$worker->public_id]->all()['DATABASE_URL'])->toBe($appEnv['DATABASE_URL']);
});

test('the worker\'s own connection wins over the inherited one', function (): void {
    [, $project, $production, $app, $worker, $postgres] = wcProject();
    $other = Projects::postgres($project, 'reports');
    resolve(CreateConnection::class)->handle($app, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    resolve(CreateConnection::class)->handle($worker, $other, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());

    expect(wcEnv($project, $production, $worker)['DATABASE_URL'])->toContain('@reports:5432')
        ->and(wcEnv($project, $production, $app)['DATABASE_URL'])->toContain('@postgres:5432')
        // Nothing of the app's connection reaches the worker: no inherited edge.
        ->and(collect(resolve(CanvasGraph::class)->for($production)['edges'])->where('type', 'inherited')->count())->toBe(0);
});

test('changing the app\'s connections restarts its worker with the next deploy', function (): void {
    [, $project, $production, $app, $worker, $postgres] = wcProject();
    $before = resolve(CurrentEnvironmentSpec::class)->get($production);

    $connection = resolve(CreateConnection::class)->handle($app, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    $connected = resolve(CurrentEnvironmentSpec::class)->get($production);

    expect(SpecDiff::between($before, $connected)->effectFor($worker->public_id)->needsDeployment())->toBeTrue();

    resolve(DeleteConnection::class)->handle($connection, AuditActor::system());
    $removed = resolve(CurrentEnvironmentSpec::class)->get($production);

    expect(SpecDiff::between($connected, $removed)->effectFor($worker->public_id)->needsDeployment())->toBeTrue()
        ->and(wcEnv($project, $production, $worker))->not->toHaveKey('DATABASE_URL')
        ->and(SpecDiff::between($connected, $connected)->effectFor($worker->public_id))->toBe(ChangeEffect::None);
});

test('the canvas draws an inherited edge from the worker and Variables lists the inherited rows read-only', function (): void {
    [$team, $project, $production, $app, $worker, $postgres] = wcProject();
    $connection = resolve(CreateConnection::class)->handle($app, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());

    $edges = collect(resolve(CanvasGraph::class)->for($production)['edges']);
    $inherited = $edges->firstWhere('type', 'inherited');

    expect($inherited)->toBeInstanceOf(CanvasEdgeData::class)
        ->and($inherited->id)->toBe('inherited-'.$connection->public_id.'-'.$worker->public_id)
        ->and($inherited->source)->toBe($worker->public_id)
        ->and($inherited->target)->toBe($postgres->public_id)
        ->and($inherited->via)->toBe($app->public_id)
        ->and($inherited->variables)->toBe(['DATABASE_URL'])
        ->and($edges->where('type', 'connection')->count())->toBe(1);

    $rows = collect(resolve(ServiceVariablesView::class)->get($production, $worker, memberOf($team, TeamRole::Developer))->variables)
        ->keyBy(static fn (VariableRowData $row): string => $row->key);

    expect($rows['DATABASE_URL']->connection)->toBe('postgres')
        ->and($rows['DATABASE_URL']->inheritedFrom)->toBe('api')
        ->and($rows['DATABASE_URL']->canEdit)->toBeFalse()
        ->and($rows['DATABASE_URL']->preview)->toContain('@postgres:5432');

    // With its own edge to the same database, no inherited edge is drawn.
    resolve(CreateConnection::class)->handle($worker, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());

    expect(collect(resolve(CanvasGraph::class)->for($production)['edges'])->where('type', 'inherited')->count())->toBe(0);
});
