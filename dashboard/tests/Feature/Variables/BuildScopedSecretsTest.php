<?php

declare(strict_types=1);

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Services\CreateService;
use App\Actions\Variables\CheckRequiredSecrets;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\RequiredSecret;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Services\CreateServiceData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\ServiceKind;
use App\Enums\VariableType;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\Variable;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Variables\VariableStore;
use App\Support\Variables\MissingSecretsMessage;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitFixtures;
use Tests\Support\Projects;

/*
| Build-scoped generated secrets (OD5): NEXT_SERVER_ACTIONS_ENCRYPTION_KEY
| is created for the next build, with one value per service across its
| environments, never in the env of the deployment whose build declared it.
| Required secrets (RAILS_MASTER_KEY) stop a deployment with a clear ask.
*/

const BS_SHA = '4015b57a143aec5156fd1444a017a32137a3fd0f';
const BS_KEY = 'NEXT_SERVER_ACTIONS_ENCRYPTION_KEY';

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    GitFixtures::resolve();
    Http::fake(['http://git-fixtures:8080/*' => static fn (): PromiseInterface => GitFixtures::advertise(BS_SHA, 'main')]);

    $this->team = Team::factory()->create();
    [$this->server] = Projects::server($this->team);
    $this->project = Projects::create($this->team, 'shop');
    $this->production = $this->project->production()->sole();
    $this->web = resolve(CreateService::class)->handle($this->project, CreateServiceData::from([
        'name' => 'web',
        'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/next', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
    ]), null, AuditActor::system());
});

function bsDeploy(Environment $environment): void
{
    resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());
}

function bsLatest(Service $service, Environment $environment): Deployment
{
    return Deployment::query()
        ->where('environment_id', $environment->id)
        ->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))
        ->latest('id')
        ->firstOrFail();
}

/**
 * Completes every pending build task with a Next.js summary.
 *
 * @param  array<string, mixed>  $summary
 */
function bsBuild(Server $server, Service $app, array $summary): void
{
    $tasks = resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 16, 'types' => ['build']]));

    foreach ($tasks as $leased) {
        $task = DaemonTask::query()->where('public_id', $leased->id)->sole();
        resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
            'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt,
            'outcome' => 'succeeded',
            'build' => [
                'image' => '127.0.0.1:5000/apps/'.strtolower($app->public_id).'@sha256:'.str_repeat('d', 64),
                'durationMs' => 42000,
                'summary' => ['builder' => 'railpack', 'builderReason' => 'preset', 'railpackVersion' => '0.42.0', 'provider' => 'node', 'framework' => 'next', 'port' => 3000, ...$summary],
                'secretsHash' => str_repeat('e', 64),
                'attestations' => ['sbom', 'provenance'],
            ],
        ]));
    }
}

/**
 * @return array<string, string>
 */
function bsSnapshot(Deployment $deployment): array
{
    $deployment->refresh();

    return resolve(ProjectKeyring::class)->decryptSnapshot($deployment->environment->project, $deployment->public_id, (string) $deployment->env_snapshot);
}

/**
 * @return list<Variable>
 */
function bsRows(Service $service): array
{
    return Variable::query()->where('service_id', $service->id)->where('key', BS_KEY)->orderBy('id')->with('targets')->get()->all();
}

const BS_DECLARED = ['generatedSecrets' => [['name' => BS_KEY, 'length' => 32, 'encoding' => 'base64', 'scope' => 'build', 'shared' => 'service']]];

test('a build-scoped key is created for the next build, with one value across environments', function (): void {
    $staging = resolve(CreateEnvironment::class)->handle($this->project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    bsDeploy($this->production);
    bsBuild($this->server, $this->web, BS_DECLARED);

    $rows = bsRows($this->web);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->type)->toBe(VariableType::Secret)
        ->and($rows[0]->generator)->toBeNull()
        ->and($rows[0]->targets->pluck('environment_id')->all())->toBe([$this->production->id]);

    $value = resolve(VariableStore::class)->plaintext($this->project, $rows[0]);
    expect(base64_decode($value, true))->not->toBeFalse()
        ->and(strlen((string) base64_decode($value, true)))->toBe(32)
        // The image was built without it: this deployment doesn't get it.
        ->and(bsSnapshot(bsLatest($this->web, $this->production)))->not->toHaveKey(BS_KEY);

    // Another environment gets the same value, not a second one.
    bsDeploy($staging);
    bsBuild($this->server, $this->web, BS_DECLARED);

    $rows = bsRows($this->web);
    expect($rows)->toHaveCount(2)
        ->and(resolve(VariableStore::class)->plaintext($this->project, $rows[1]))->toBe($value)
        ->and($rows[1]->targets->pluck('environment_id')->all())->toBe([$staging->id]);

    // The next production build has it.
    for ($i = 0; $i < 4; $i++) {
        Projects::report($this->server);
    }

    bsDeploy($this->production);
    expect(bsSnapshot(bsLatest($this->web, $this->production))[BS_KEY] ?? null)->toBe($value);
    bsBuild($this->server, $this->web, BS_DECLARED);
    expect(bsRows($this->web))->toHaveCount(2);
});

test('a key the user set is kept', function (): void {
    resolve(CreateVariable::class)->handle($this->project, $this->web, VariableInput::from([
        'key' => BS_KEY, 'value' => base64_encode(str_repeat('u', 32)), 'type' => VariableType::Secret, 'targets' => [VariableTargetData::environment($this->production->public_id)],
    ]), null, AuditActor::system());

    bsDeploy($this->production);
    bsBuild($this->server, $this->web, BS_DECLARED);

    expect(bsRows($this->web))->toHaveCount(1);
});

test('a missing required secret stops the deployment with what to add', function (): void {
    bsDeploy($this->production);
    bsBuild($this->server, $this->web, ['requiredSecrets' => [['name' => 'RAILS_MASTER_KEY', 'reason' => 'the contents of config/master.key']]]);

    $deployment = bsLatest($this->web, $this->production)->refresh();
    expect(resolve(CheckRequiredSecrets::class)->handle($deployment))
        ->toBe('web needs RAILS_MASTER_KEY (the contents of config/master.key). Add it as a secret variable, then deploy again.');

    resolve(CreateVariable::class)->handle($this->project, $this->web, VariableInput::from([
        'key' => 'RAILS_MASTER_KEY', 'value' => str_repeat('a', 32), 'type' => VariableType::Secret, 'targets' => [VariableTargetData::environment($this->production->public_id)],
    ]), null, AuditActor::system());
    bsDeploy($this->production);
    bsBuild($this->server, $this->web, ['requiredSecrets' => [['name' => 'RAILS_MASTER_KEY']]]);

    expect(resolve(CheckRequiredSecrets::class)->handle(bsLatest($this->web, $this->production)->refresh()))->toBeNull();
});

test('the ask names several secrets at once', function (): void {
    expect(MissingSecretsMessage::for('web', [new RequiredSecret('A_KEY'), new RequiredSecret('B_KEY', 'from your provider')]))
        ->toBe('web needs A_KEY and B_KEY (from your provider). Add them as secret variables, then deploy again.');
});
