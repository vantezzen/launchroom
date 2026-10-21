<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DomainKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use App\Models\Volume;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Support\Projects;

/*
| Role × own/other team for every policy ability of the project domain
| (docs/10 §2.4). Expected values follow TeamRole::permissions().
*/

beforeEach(fn () => Projects::useTestKek());

dataset('project roles', [
    'owner' => [TeamRole::Owner],
    'admin' => [TeamRole::Admin],
    'developer' => [TeamRole::Developer],
    'viewer' => [TeamRole::Viewer],
]);

/**
 * @return array<string, bool>
 */
function projectAbilities(User $user, Team $team): array
{
    Projects::server($team);
    $project = Projects::create($team);
    $production = $project->production()->sole();
    $postgres = Projects::postgres($project);
    $web = Projects::image($project);
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    $connection = resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    $config = Variable::query()->where('key', 'POSTGRES_USER')->sole();
    $secret = Variable::query()->where('key', 'POSTGRES_PASSWORD')->sole();
    $owned = Variable::query()->where('connection_id', $connection->id)->sole();
    $domain = Domain::query()->sole();
    $custom = Domain::query()->create(['team_id' => $team->id, 'service_instance_id' => $instance->id, 'hostname' => 'shop-'.$team->id.'.example.com', 'kind' => DomainKind::Custom]);
    $volume = Volume::query()->sole();

    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    Projects::report(Server::query()->where('team_id', $team->id)->sole());
    Projects::report(Server::query()->where('team_id', $team->id)->sole());
    resolve(CreateVariable::class)->handle($project, $web, VariableInput::from(['key' => 'X', 'value' => '1', 'type' => VariableType::Config, 'targets' => [VariableTargetData::environment($production->public_id)]]), null, AuditActor::system());
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    Projects::report(Server::query()->where('team_id', $team->id)->sole());
    Projects::report(Server::query()->where('team_id', $team->id)->sole());
    $superseded = Deployment::query()->where('status', 'superseded')->firstOrFail();

    $gate = Gate::forUser($user);

    return [
        'project.viewAny' => $gate->allows('viewAny', [Project::class, $team]),
        'project.view' => $gate->allows('view', $project),
        'project.create' => $gate->allows('create', [Project::class, $team]),
        'project.update' => $gate->allows('update', $project),
        'project.delete' => $gate->allows('delete', $project),
        'environment.view' => $gate->allows('view', $production),
        'environment.create' => $gate->allows('create', [Environment::class, $project]),
        'environment.update' => $gate->allows('update', $production),
        'environment.deploy' => $gate->allows('deploy', $production),
        'environment.delete' => $gate->allows('delete', $production),
        'service.view' => $gate->allows('view', $web),
        'service.create' => $gate->allows('create', [Service::class, $project]),
        'service.update' => $gate->allows('update', $web),
        'service.delete' => $gate->allows('delete', $web),
        'instance.view' => $gate->allows('view', $instance),
        'instance.update' => $gate->allows('update', $instance),
        'variable.view' => $gate->allows('view', $config),
        'variable.create' => $gate->allows('create', [Variable::class, $project]),
        'variable.update' => $gate->allows('update', $config),
        'variable.updateOwned' => $gate->allows('update', $owned),
        'variable.delete' => $gate->allows('delete', $config),
        'variable.revealConfig' => $gate->allows('reveal', $config),
        'variable.revealSecret' => $gate->allows('reveal', $secret),
        'connection.view' => $gate->allows('view', $connection),
        'connection.create' => $gate->allows('create', [Connection::class, $project]),
        'connection.delete' => $gate->allows('delete', $connection),
        'domain.view' => $gate->allows('view', $domain),
        'domain.create' => $gate->allows('create', [Domain::class, $instance]),
        'domain.delete' => $gate->allows('delete', $domain),
        'domain.updateGenerated' => $gate->allows('update', $domain),
        'domain.updateCustom' => $gate->allows('update', $custom),
        'deployment.view' => $gate->allows('view', $superseded),
        'deployment.rollback' => $gate->allows('rollback', $superseded),
        'volume.view' => $gate->allows('view', $volume),
    ];
}

test('own team: abilities follow the role matrix', function (TeamRole $role): void {
    $team = Team::factory()->create();
    $user = memberOf($team, $role);

    $write = $role !== TeamRole::Viewer;
    $secrets = in_array($role, [TeamRole::Owner, TeamRole::Admin], true);

    expect(projectAbilities($user, $team))->toBe([
        'project.viewAny' => true,
        'project.view' => true,
        'project.create' => $write,
        'project.update' => $write,
        'project.delete' => $write,
        'environment.view' => true,
        'environment.create' => $write,
        'environment.update' => $write,
        'environment.deploy' => $write,
        // Production can never be deleted.
        'environment.delete' => false,
        'service.view' => true,
        'service.create' => $write,
        'service.update' => $write,
        'service.delete' => $write,
        'instance.view' => true,
        'instance.update' => $write,
        'variable.view' => true,
        'variable.create' => $write,
        'variable.update' => $write,
        // Connection-owned variables change through their connection.
        'variable.updateOwned' => false,
        'variable.delete' => $write,
        'variable.revealConfig' => true,
        'variable.revealSecret' => $secrets,
        'connection.view' => true,
        'connection.create' => $write,
        'connection.delete' => $write,
        'domain.view' => true,
        'domain.create' => $write,
        'domain.delete' => $write,
        // www redirect and DNS re-checks exist for custom domains only.
        'domain.updateGenerated' => false,
        'domain.updateCustom' => $write,
        'deployment.view' => true,
        'deployment.rollback' => $write,
        'volume.view' => true,
    ]);
})->with('project roles');

test('other team: every ability is denied whatever the role', function (TeamRole $role): void {
    $user = memberOf(Team::factory()->create(), $role);

    expect(array_filter(projectAbilities($user, Team::factory()->create())))->toBe([]);
})->with('project roles');

test('route bindings resolve project resources only within the user\'s teams', function (): void {
    $team = Team::factory()->create();
    $user = memberOf($team, TeamRole::Viewer);
    $mine = Projects::create($team);
    $theirs = Projects::create(Team::factory()->create());

    Route::middleware(['web', 'auth'])->get('/_test/projects/{project}', static fn (Project $project): string => $project->public_id);

    $this->actingAs($user)->get("/_test/projects/{$mine->public_id}")->assertOk()->assertSee($mine->public_id);
    $this->actingAs($user)->get("/_test/projects/{$theirs->public_id}")->assertNotFound();
    $this->actingAs($user)->get('/_test/projects/prj_nope')->assertNotFound();
});
