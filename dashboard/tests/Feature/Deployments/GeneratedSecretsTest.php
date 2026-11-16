<?php

declare(strict_types=1);

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Services\CreateService;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\GenerateDeclaredSecrets;
use App\Actions\Variables\SetSharedVariableUsage;
use App\Data\Audit\AuditActor;
use App\Data\Canvas\DetectedPlanData;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Services\CreateServiceData;
use App\Data\Specs\ServiceSpec;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ServiceKind;
use App\Enums\VariableType;
use App\Models\AuditEvent;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Variable;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Variables\SpecVariableResolver;
use App\Services\Variables\VariableStore;
use App\Support\Changes\SpecDiff;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitFixtures;
use Tests\Support\Projects;

/*
| Secrets a build declares (summary.generatedSecrets, D86): Laravel's
| APP_KEY is created once per service and environment as a secret
| variable, before anything runs with the env.
*/

const GS_SHA = '4015b57a143aec5156fd1444a017a32137a3fd0f';
const GS_APP_KEY = ['name' => 'APP_KEY', 'length' => 32, 'encoding' => 'alnum'];

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    GitFixtures::resolve();
    Http::fake(['http://git-fixtures:8080/*' => static fn (): PromiseInterface => GitFixtures::advertise(GS_SHA, 'main')]);

    $this->team = Team::factory()->create();
    [$this->server, $this->keypair] = Projects::server($this->team);
    $this->project = Projects::create($this->team, 'shop');
    $this->production = $this->project->production()->sole();
    $this->api = resolve(CreateService::class)->handle($this->project, CreateServiceData::from([
        'name' => 'api',
        'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/laravel', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
    ]), null, AuditActor::system());
});

function gsDeploy(Environment $environment): void
{
    resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());
}

function gsLatest(Service $service, Environment $environment): Deployment
{
    return Deployment::query()
        ->where('environment_id', $environment->id)
        ->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))
        ->latest('id')
        ->firstOrFail();
}

/**
 * Completes every pending build task with a Laravel summary.
 *
 * @param  list<array<string, mixed>>  $secrets
 */
function gsBuild(Server $server, Service $app, array $secrets = [GS_APP_KEY]): void
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
                'summary' => ['builder' => 'railpack', 'builderReason' => 'preset', 'railpackVersion' => '0.42.0', 'provider' => 'php', 'framework' => 'laravel', 'port' => 8080, ...($secrets === [] ? [] : ['generatedSecrets' => $secrets])],
                'secretsHash' => str_repeat('e', 64),
                'attestations' => ['sbom', 'provenance'],
            ],
        ]));
    }
}

/**
 * The env launchroomd gets for $deployment's workload.
 *
 * @return array<string, string>
 */
function gsWorkloadEnv(Server $server, string $keypair, Deployment $deployment): array
{
    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('deployment', $deployment->public_id);
    expect($workload)->not->toBeNull();
    $plain = sodium_crypto_box_seal_open((string) base64_decode((string) $workload['env']['sealed'], true), $keypair);

    return json_decode((string) $plain, true, 64, JSON_THROW_ON_ERROR)['env'];
}

/**
 * @return list<Variable>
 */
function gsRows(Service $service, string $key = 'APP_KEY'): array
{
    return Variable::query()->where('service_id', $service->id)->where('key', $key)->orderBy('id')->get()->all();
}

function gsPlaintext(Project $project, Variable $variable): string
{
    return resolve(VariableStore::class)->plaintext($project, $variable);
}

test('a missing APP_KEY is created once as a secret variable, applied, and in the env the app starts with', function (): void {
    gsDeploy($this->production);
    gsBuild($this->server, $this->api);

    $deployment = gsLatest($this->api, $this->production);
    $rows = gsRows($this->api);

    expect($deployment->status)->toBe(DeploymentStatus::RollingOut)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->type)->toBe(VariableType::Secret)
        ->and($rows[0]->generator)->toBe('secret(32)')
        ->and($rows[0]->targets->pluck('environment_id')->all())->toBe([$this->production->id]);

    $key = gsPlaintext($this->project, $rows[0]);
    expect($key)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and(gsWorkloadEnv($this->server, $this->keypair, $deployment)['APP_KEY'])->toBe($key);

    // Applied at once: nothing shows up as a staged change, Discard can't drop it.
    $this->production->refresh();
    expect(SpecDiff::between($this->production->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($this->production))->isEmpty())->toBeTrue()
        ->and($this->production->lastAppliedRevision?->message)->toBe('Generated APP_KEY for api');

    $audit = AuditEvent::query()->where('action', AuditAction::VariableCreated->value)->where('subject_id', $rows[0]->public_id)->sole();
    expect($audit->meta)->toMatchArray(['key' => 'APP_KEY', 'generated' => true, 'deployment' => $deployment->public_id])
        ->and(json_encode($audit->meta))->not->toContain($key);

    // The next deploy keeps it.
    for ($i = 0; $i < 4; $i++) {
        Projects::report($this->server);
    }

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Active);
    resolve(CreateVariable::class)->handle($this->project, $this->api, VariableInput::from([
        'key' => 'APP_NAME', 'value' => 'Shop', 'type' => VariableType::Config, 'targets' => [VariableTargetData::environment($this->production->public_id)],
    ]), null, AuditActor::system());
    gsDeploy($this->production);
    gsBuild($this->server, $this->api);
    expect(gsLatest($this->api, $this->production)->is($deployment))->toBeFalse();

    expect(gsRows($this->api))->toHaveCount(1)
        ->and(gsWorkloadEnv($this->server, $this->keypair, gsLatest($this->api, $this->production))['APP_KEY'])->toBe($key);
});

test('a variable the user set wins and is never overwritten', function (string $source): void {
    $input = ['key' => 'APP_KEY', 'value' => 'base64:'.base64_encode(str_repeat('k', 32)), 'type' => VariableType::Secret, 'targets' => [VariableTargetData::environment($this->production->public_id)]];

    if ($source === 'shared') {
        $shared = resolve(CreateVariable::class)->handle($this->project, null, VariableInput::from($input), null, AuditActor::system());
        resolve(SetSharedVariableUsage::class)->handle($shared, $this->api, true);
    } else {
        resolve(CreateVariable::class)->handle($this->project, $this->api, VariableInput::from($input), null, AuditActor::system());
    }

    gsDeploy($this->production);
    gsBuild($this->server, $this->api);

    expect(Variable::query()->where('key', 'APP_KEY')->count())->toBe(1)
        ->and(gsWorkloadEnv($this->server, $this->keypair, gsLatest($this->api, $this->production))['APP_KEY'])->toBe($input['value']);
})->with(['service', 'shared']);

test('without a declared secret nothing is generated (the decision was removed)', function (): void {
    gsDeploy($this->production);
    gsBuild($this->server, $this->api, []);

    expect(gsRows($this->api))->toBe([])
        ->and(gsWorkloadEnv($this->server, $this->keypair, gsLatest($this->api, $this->production)))->not->toHaveKey('APP_KEY');
});

test('every environment gets its own value, also one copied from an environment that has one', function (): void {
    $staging = resolve(CreateEnvironment::class)->handle($this->project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    gsDeploy($this->production);
    gsDeploy($staging);
    gsBuild($this->server, $this->api);
    gsBuild($this->server, $this->api);

    $rows = gsRows($this->api);
    expect($rows)->toHaveCount(2);

    $production = gsWorkloadEnv($this->server, $this->keypair, gsLatest($this->api, $this->production))['APP_KEY'];
    $stagingKey = gsWorkloadEnv($this->server, $this->keypair, gsLatest($this->api, $staging))['APP_KEY'];
    expect($production)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($stagingKey)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($stagingKey)->not->toBe($production);

    // A copy regenerates from the stored generator.
    $qa = resolve(CreateEnvironment::class)->handle($this->project, new CreateEnvironmentData('qa'), null, AuditActor::system());
    $copy = Variable::query()->where('service_id', $this->api->id)->where('key', 'APP_KEY')->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $qa->id))->sole();
    expect($copy->generator)->toBe('secret(32)')
        ->and(gsPlaintext($this->project, $copy))->not->toBeIn([$production, $stagingKey]);
});

test('a deployment whose env predates the key gets the same value instead of a second one', function (): void {
    gsDeploy($this->production);
    $instance = ServiceInstance::query()->where('service_id', $this->api->id)->sole();
    $revision = $this->production->refresh()->lastAppliedRevision;
    $spec = $revision?->spec;
    $service = $spec?->service($this->api->public_id);
    expect($service)->toBeInstanceOf(ServiceSpec::class);

    // A second deployment created before the first one's build finished.
    $second = resolve(DeploymentFactory::class)->create($this->project, $instance, $revision, $spec, $service, resolve(SpecVariableResolver::class)->resolve($this->project, $spec, $service), DeploymentTrigger::Manual, null);

    gsBuild($this->server, $this->api);
    $first = Deployment::query()->where('service_instance_id', $instance->id)->oldest('id')->firstOrFail();
    expect($first->is($second))->toBeFalse();

    $second->refresh()->forceFill(['plan' => $first->refresh()->plan])->save();
    expect(resolve(GenerateDeclaredSecrets::class)->handle($second))->toBe(['APP_KEY'])
        ->and(gsRows($this->api))->toHaveCount(1);

    $keyring = resolve(ProjectKeyring::class);
    $firstEnv = $keyring->decryptSnapshot($this->project, $first->public_id, (string) $first->refresh()->env_snapshot);
    $secondEnv = $keyring->decryptSnapshot($this->project, $second->public_id, (string) $second->refresh()->env_snapshot);
    expect($secondEnv['APP_KEY'])->toBe($firstEnv['APP_KEY'])
        ->and($secondEnv['APP_KEY'])->toBe(gsPlaintext($this->project, gsRows($this->api)[0]));

    // Running it again changes nothing.
    expect(resolve(GenerateDeclaredSecrets::class)->handle($second->refresh()))->toBe([]);
});

test('workers and crons share their app\'s key through a reference', function (): void {
    $worker = resolve(CreateService::class)->handle($this->project, CreateServiceData::from(['name' => 'queue', 'kind' => ServiceKind::Worker, 'parentServiceId' => $this->api->public_id]), null, AuditActor::system());

    gsDeploy($this->production);
    gsBuild($this->server, $this->api);

    $appKey = gsPlaintext($this->project, gsRows($this->api)[0]);
    $workerRows = gsRows($worker);

    expect($workerRows)->toHaveCount(1)
        ->and($workerRows[0]->references)->toBe(['api.APP_KEY'])
        ->and(gsWorkloadEnv($this->server, $this->keypair, gsLatest($worker, $this->production))['APP_KEY'])->toBe($appKey)
        ->and(SpecDiff::between($this->production->refresh()->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($this->production))->isEmpty())->toBeTrue();
});

test('plan previews list a declared secret as created by launchroom, not as missing', function (): void {
    $summary = BuildSummary::from([
        'builder' => 'railpack', 'builderReason' => 'preset', 'framework' => 'laravel',
        'envReferences' => ['APP_KEY', 'APP_NAME'],
        'generatedSecrets' => [GS_APP_KEY],
    ]);

    $fresh = DetectedPlanData::fromSummary($summary, []);
    expect($fresh->missingVariables)->toBe(['APP_NAME'])
        ->and($fresh->generatedSecrets)->toBe(['APP_KEY']);

    $defined = DetectedPlanData::fromSummary($summary, ['APP_KEY']);
    expect($defined->generatedSecrets)->toBe([])
        ->and($defined->missingVariables)->toBe(['APP_NAME']);

    expect(DetectedPlanData::fromSummary($summary)->generatedSecrets)->toBe(['APP_KEY']);
});
