<?php

declare(strict_types=1);

use App\Actions\Environments\CreateEnvironment;
use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Services\CreateServiceData;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\DomainKind;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\ProjectKey;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Variable;
use App\Models\Volume;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Variables\VariableStore;
use Illuminate\Validation\ValidationException;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('creating a project creates production, a subnet, a canvas and a wrapped data key', function (): void {
    $project = Projects::create(Team::factory()->create(), 'My Shop');
    $production = $project->production()->sole();

    expect($project->slug)->toBe('my-shop')
        ->and($project->public_id)->toStartWith('prj_')
        ->and($production->kind)->toBe(EnvironmentKind::Production)
        ->and($production->network_subnet)->toBe('10.231.0.0/24')
        ->and($production->canvasLayout()->exists())->toBeTrue()
        ->and(ProjectKey::query()->where('project_id', $project->id)->sole()->wrapped_dek)->toStartWith('lr1:')
        ->and(AuditEvent::query()->where('action', 'project.created')->where('subject_id', $project->public_id)->exists())->toBeTrue();
});

test('project slugs are unique per team and subnets unique per install', function (): void {
    $team = Team::factory()->create();
    $first = Projects::create($team, 'Shop');
    $second = Projects::create($team, 'Shop');
    $other = Projects::create(Team::factory()->create(), 'Shop');

    expect($second->slug)->toBe('shop-2')
        ->and($other->slug)->toBe('shop')
        ->and(Environment::query()->pluck('network_subnet')->unique())->toHaveCount(3);
});

test('a database service gets per-environment secret credentials, a volume and no domain', function (): void {
    $project = Projects::create(Team::factory()->create());
    $postgres = Projects::postgres($project);
    $instance = ServiceInstance::query()->where('service_id', $postgres->id)->sole();

    $password = Variable::query()->where('service_id', $postgres->id)->where('key', 'POSTGRES_PASSWORD')->sole();
    $plaintext = resolve(VariableStore::class)->plaintext($project, $password);

    expect($postgres->slug)->toBe('postgres')
        ->and($password->type)->toBe(VariableType::Secret)
        ->and($password->generated)->toBeTrue()
        ->and($plaintext)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($password->value)->not->toContain($plaintext)
        ->and($password->toArray())->not->toHaveKey('value')
        ->and(Variable::query()->where('service_id', $postgres->id)->where('key', 'DATABASE_URL')->sole()->references)->toBe(['POSTGRES_USER', 'POSTGRES_PASSWORD', 'PRIVATE_HOST', 'POSTGRES_DB'])
        ->and(Volume::query()->where('service_instance_id', $instance->id)->sole()->mount_path)->toBe('/var/lib/postgresql')
        ->and($instance->deploy->port)->toBe(5432)
        ->and($instance->scaling->replicas)->toBe(1)
        ->and(Domain::query()->count())->toBe(0);
});

test('variable ciphertexts are bound to their row and version', function (): void {
    $project = Projects::create(Team::factory()->create());
    Projects::postgres($project);
    [$a, $b] = Variable::query()->where('type', 'secret')->get()->all() + [null, null];
    $user = Variable::query()->where('key', 'POSTGRES_USER')->sole();
    $keyring = resolve(ProjectKeyring::class);

    // Swapping a ciphertext into another row fails authentication.
    expect(fn () => $keyring->decryptVariable($project, $user->public_id, $user->version, $a->value))->toThrow(DecryptionFailed::class)
        ->and(fn () => $keyring->decryptVariable($project, $a->public_id, $a->version + 1, $a->value))->toThrow(DecryptionFailed::class);
});

test('web services get a generated domain: sslip.io, platform wildcard or localhost', function (): void {
    $team = Team::factory()->create();
    Projects::server($team, ip: '198.51.100.4');
    $project = Projects::create($team, 'shop');

    Projects::image($project, 'web');
    expect(Domain::query()->latest('id')->firstOrFail()->hostname)->toBe('web-production-shop.198-51-100-4.sslip.io');

    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com'])->save();
    Projects::image($project, 'api');
    expect(Domain::query()->latest('id')->firstOrFail()->hostname)->toBe('api-production.shop.apps.example.com');

    config(['launchroom.domains.localhost' => true]);
    Projects::image($project, 'docs');
    expect(Domain::query()->latest('id')->firstOrFail()->hostname)->toBe('docs-production.shop.localhost')
        ->and(Domain::query()->pluck('kind')->unique()->all())->toBe([DomainKind::Generated]);
});

test('no generated domain without a port, an edge IP or a platform domain', function (): void {
    $project = Projects::create(Team::factory()->create());

    Projects::image($project, 'web');
    Projects::image($project, 'worker', port: null);

    expect(Domain::query()->count())->toBe(0);
});

test('service validation: kinds need matching sources, workers need an app parent, slugs are unique', function (): void {
    $project = Projects::create(Team::factory()->create());
    $create = static fn (array $data) => resolve(CreateService::class)->handle($project, CreateServiceData::from($data), null, AuditActor::system());

    expect(fn () => $create(['name' => 'x', 'kind' => ServiceKind::Database, 'source' => ['type' => 'image', 'reference' => 'nginx:1']]))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $create(['name' => 'x', 'kind' => ServiceKind::Database, 'source' => ['type' => 'database', 'engine' => 'postgres', 'version' => '9']]))->toThrow(DomainRuleViolation::class, 'not available')
        ->and(fn () => $create(['name' => 'q', 'kind' => ServiceKind::Worker]))->toThrow(DomainRuleViolation::class, 'Workers and crons')
        ->and(fn () => $create(['name' => 'x', 'kind' => ServiceKind::Image, 'source' => ['type' => 'image', 'reference' => 'NOT VALID']]))->toThrow(ValidationException::class);

    Projects::image($project, 'web');

    expect(fn () => $create(['name' => 'web', 'slug' => 'web', 'kind' => ServiceKind::Image, 'source' => ['type' => 'image', 'reference' => 'nginx:1']]))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $create(['name' => 'x', 'slug' => 'shared', 'kind' => ServiceKind::Image, 'source' => ['type' => 'image', 'reference' => 'nginx:1']]))->toThrow(DomainRuleViolation::class)
        ->and(Projects::image($project, 'web')->slug)->toBe('web-2');
});

test('workers take their parent app\'s source and have no probe', function (): void {
    $project = Projects::create(Team::factory()->create());
    $app = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'api', 'kind' => ServiceKind::App, 'source' => ['type' => 'git', 'repositoryId' => 42, 'fullName' => 'acme/api']]), null, AuditActor::system());
    $worker = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'queue', 'kind' => ServiceKind::Worker, 'parentServiceId' => $app->public_id, 'startCommand' => ['php', 'artisan', 'queue:work']]), null, AuditActor::system());
    $instance = ServiceInstance::query()->where('service_id', $worker->id)->sole();

    expect($worker->source)->toEqual($app->source)
        ->and($worker->parent_service_id)->toBe($app->id)
        ->and($instance->deploy->startCommand)->toBe(['php', 'artisan', 'queue:work'])
        ->and($instance->deploy->healthcheck->type->value)->toBe('none');
});

test('a persistent environment copies the service graph with its own subnet, credentials and domains', function (): void {
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team, 'shop');
    $postgres = Projects::postgres($project);
    Projects::image($project, 'web');

    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('Staging'), null, AuditActor::system());
    $production = $project->production()->sole();

    $passwords = Variable::query()->where('service_id', $postgres->id)->where('key', 'POSTGRES_PASSWORD')->with('targets')->get();
    $store = resolve(VariableStore::class);

    expect($staging->slug)->toBe('staging')
        ->and($staging->kind)->toBe(EnvironmentKind::Persistent)
        ->and($staging->base_environment_id)->toBe($production->id)
        ->and($staging->network_subnet)->not->toBe($production->network_subnet)
        ->and($staging->serviceInstances()->count())->toBe(2)
        ->and($passwords)->toHaveCount(2)
        ->and($store->plaintext($project, $passwords[0]))->not->toBe($store->plaintext($project, $passwords[1]))
        ->and(Domain::query()->pluck('hostname')->all())->toContain('web-staging-shop.203-0-113-7.sslip.io')
        ->and(fn () => resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system()))->toThrow(DomainRuleViolation::class);
});

test('databases can not scale beyond one replica', function (): void {
    $project = Projects::create(Team::factory()->create());
    $instance = ServiceInstance::query()->where('service_id', Projects::postgres($project)->id)->sole();

    expect(fn () => resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['scaling' => new ScalingSettings(replicas: 2)])))
        ->toThrow(DomainRuleViolation::class, 'single replica');
});
