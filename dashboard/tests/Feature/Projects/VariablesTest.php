<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Connections\DeleteConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Domains\AddDomain;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Environments\DiscardChanges;
use App\Actions\Services\RemoveService;
use App\Actions\Services\UpdateServiceSettings;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\DeleteVariable;
use App\Actions\Variables\ImportVariables;
use App\Actions\Variables\RevealSecret;
use App\Actions\Variables\SetSharedVariableUsage;
use App\Actions\Variables\UpdateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Specs\ChangeItem;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Data\Variables\VariableUpdate;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\TeamRole;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Variables\SpecVariableResolver;
use App\Services\Variables\VariableStore;
use App\Support\Changes\SpecDiff;
use Illuminate\Validation\ValidationException;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

function variableInput(string $key, string $value, Environment $environment, VariableType $type = VariableType::Config, array $extra = []): VariableInput
{
    return VariableInput::from(['key' => $key, 'value' => $value, 'type' => $type, 'targets' => [VariableTargetData::environment($environment->public_id)], ...$extra]);
}

/**
 * @return array<string, string>
 */
function resolvedEnv(Project $project, Environment $environment, Service $service): array
{
    $spec = resolve(CurrentEnvironmentSpec::class)->get($environment);

    return resolve(SpecVariableResolver::class)->resolve($project, $spec, $spec->services[$service->public_id])->all();
}

function stagedPaths(Environment $environment): array
{
    $environment->refresh();

    return array_map(static fn (ChangeItem $item): string => $item->path, SpecDiff::between($environment->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($environment))->items);
}

test('variables are stored encrypted and verbatim, with generated values evaluated once', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $web = Projects::image($project);

    $value = "  spaced\nmultiline \$NOT_EXPANDED  ";
    $plain = resolve(CreateVariable::class)->handle($project, $web, variableInput('PLAIN', $value, $production), null, AuditActor::system());
    $generated = resolve(CreateVariable::class)->handle($project, $web, variableInput('APP_KEY', 'base64:${{ secret(32, "base64") }}', $production, VariableType::Secret), null, AuditActor::system());
    $store = resolve(VariableStore::class);

    expect($store->plaintext($project, $plain))->toBe($value)
        ->and($plain->value)->toStartWith('lr1:')
        ->and($plain->value)->not->toContain('spaced')
        ->and($generated->generated)->toBeTrue()
        ->and($store->plaintext($project, $generated))->toMatch('/^base64:[A-Za-z0-9+\/]{43}=$/')
        ->and(AuditEvent::query()->where('action', 'variable.created')->get()->pluck('meta')->toJson())->not->toContain('spaced');
});

test('variable validation: names, browser-inlined secrets, malformed references, overlaps', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $web = Projects::image($project);
    $create = static fn (VariableInput $input) => resolve(CreateVariable::class)->handle($project, $web, $input, null, AuditActor::system());

    expect(fn () => $create(variableInput('NEXT_PUBLIC_KEY', 'x', $production, VariableType::Secret)))->toThrow(DomainRuleViolation::class, 'browser bundle')
        ->and(fn () => $create(variableInput('A', '${{ not valid }}', $production)))->toThrow(DomainRuleViolation::class, 'Not a valid reference')
        ->and(fn () => $create(variableInput('A', "x\0y", $production)))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $create(variableInput('1BAD', 'x', $production)))->toThrow(DomainRuleViolation::class)
        ->and(fn (): VariableInput => VariableInput::validateAndCreate(['key' => '1BAD', 'value' => 'x', 'type' => 'config', 'targets' => [['environmentId' => $production->public_id]]]))->toThrow(ValidationException::class);

    $create(variableInput('A', '1', $production));

    expect(fn () => $create(variableInput('A', '2', $production)))->toThrow(DomainRuleViolation::class, 'already defined')
        ->and(fn () => $create(VariableInput::from(['key' => 'B', 'value' => 'x', 'type' => VariableType::Config, 'targets' => [VariableTargetData::environment(Projects::create(Team::factory()->create())->production()->sole()->public_id)]])))
        ->toThrow(DomainRuleViolation::class, 'does not belong');
});

test('precedence: system < shared opt-in < connection < environment variable', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $postgres = Projects::postgres($project);
    $web = Projects::image($project);

    $shared = resolve(CreateVariable::class)->handle($project, null, variableInput('DATABASE_URL', 'from-shared', $production), null, AuditActor::system());
    resolve(SetSharedVariableUsage::class)->handle($shared, $web, true);
    expect(resolvedEnv($project, $production, $web)['DATABASE_URL'])->toBe('from-shared');

    resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    expect(resolvedEnv($project, $production, $web)['DATABASE_URL'])->toStartWith('postgresql://app:');

    resolve(CreateVariable::class)->handle($project, $web, variableInput('DATABASE_URL', 'explicit', $production), null, AuditActor::system());
    resolve(CreateVariable::class)->handle($project, $web, variableInput('PORT', '9999', $production), null, AuditActor::system());

    expect(resolvedEnv($project, $production, $web))->toMatchArray(['DATABASE_URL' => 'explicit', 'PORT' => '9999', 'HOST' => '0.0.0.0', 'LAUNCHROOM_SERVICE' => 'web']);
});

test('shared variables not opted into are reachable only by reference', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $web = Projects::image($project);
    resolve(CreateVariable::class)->handle($project, null, variableInput('REGION', 'eu', $production), null, AuditActor::system());
    resolve(CreateVariable::class)->handle($project, $web, variableInput('WHERE', 'in ${{shared.REGION}}', $production), null, AuditActor::system());

    $env = resolvedEnv($project, $production, $web);

    expect($env)->not->toHaveKey('REGION')
        ->and($env['WHERE'])->toBe('in eu');
});

test('references are environment-relative: staging resolves to its own database', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $postgres = Projects::postgres($project);
    $web = Projects::image($project);
    resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Laravel, null, AuditActor::system());
    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    $prod = resolvedEnv($project, $production, $web);
    $stage = resolvedEnv($project, $staging, $web);

    expect($prod['DB_CONNECTION'])->toBe('pgsql')
        ->and($prod['DB_HOST'])->toBe('postgres')
        ->and($prod['DB_PASSWORD'])->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($stage['DB_PASSWORD'])->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($stage['DB_PASSWORD'])->not->toBe($prod['DB_PASSWORD']);
});

test('connections: framework-aware injection, owned variables, app-to-app URLs, deletion', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $postgres = Projects::postgres($project);
    $api = Projects::image($project, 'api', port: 8000);
    $web = Projects::image($project, 'web');

    $connection = resolve(CreateConnection::class)->handle($web, $api, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());

    expect($connection->injected)->toBe(['API_URL'])
        ->and(resolvedEnv($project, $production, $web)['API_URL'])->toBe('http://api:8000')
        ->and(fn () => resolve(CreateConnection::class)->handle($web, $api, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system()))->toThrow(DomainRuleViolation::class, 'already connected')
        ->and(fn () => resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Public, ConnectionStyle::Url, null, AuditActor::system()))->toThrow(DomainRuleViolation::class, 'private')
        ->and(fn () => resolve(UpdateVariable::class)->handle(Variable::query()->where('connection_id', $connection->id)->sole(), VariableUpdate::from(['value' => 'x']), null, AuditActor::system()))->toThrow(DomainRuleViolation::class, 'connection');

    resolve(DeleteConnection::class)->handle($connection, AuditActor::system());

    expect(Variable::query()->where('key', 'API_URL')->exists())->toBeFalse()
        ->and(resolvedEnv($project, $production, $web))->not->toHaveKey('API_URL');
});

test('.env import creates and updates with detected types, refusing broken files', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $web = Projects::image($project);
    $targets = [VariableTargetData::environment($production->public_id)];
    $import = static fn (string $content) => resolve(ImportVariables::class)->handle($project, $web, $content, $targets, null, AuditActor::system());

    $result = $import("APP_NAME=Shop\nSTRIPE_SECRET=sk_live_123\nNEXT_PUBLIC_URL=\"https://shop.example.com\"\n");

    expect($result->created)->toBe(['APP_NAME', 'STRIPE_SECRET', 'NEXT_PUBLIC_URL'])
        ->and(Variable::query()->where('key', 'STRIPE_SECRET')->sole()->type)->toBe(VariableType::Secret)
        ->and(Variable::query()->where('key', 'NEXT_PUBLIC_URL')->sole()->exposure())->toBe(VariableExposure::Both);

    $again = $import("APP_NAME=Shop 2\n");

    expect($again->updated)->toBe(['APP_NAME'])
        ->and(Variable::query()->where('key', 'APP_NAME')->sole()->version)->toBe(2)
        ->and(resolvedEnv($project, $production, $web)['APP_NAME'])->toBe('Shop 2')
        ->and(fn () => $import("OK=1\nbroken line"))->toThrow(DomainRuleViolation::class, 'Line 2');
});

test('revealing a secret is audited without the value', function (): void {
    $project = Projects::create(Team::factory()->create());
    $variable = resolve(CreateVariable::class)->handle($project, Projects::image($project), variableInput('API_TOKEN', 'tok_123', $project->production()->sole(), VariableType::Secret), null, AuditActor::system());

    expect(resolve(RevealSecret::class)->handle($variable, AuditActor::system()))->toBe('tok_123');

    $event = AuditEvent::query()->where('action', 'variable.secret_revealed')->sole();

    expect($event->subject_id)->toBe($variable->public_id)
        ->and(json_encode($event->meta))->not->toContain('tok_123');
});

test('staged changes appear after edits and disappear after deploy', function (): void {
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team);
    $production = $project->production()->sole();
    $web = Projects::image($project);

    expect(stagedPaths($production))->toBe(["services.{$web->public_id}"]);

    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    expect(stagedPaths($production))->toBe([]);

    resolve(UpdateServiceSettings::class)->handle(ServiceInstance::query()->where('service_id', $web->id)->sole(), UpdateServiceSettingsData::from(['scaling' => new ScalingSettings(replicas: 2)]));
    expect(stagedPaths($production))->toBe(["services.{$web->public_id}.scaling.replicas"]);
});

test('discard restores every kind of staged change from the last revision', function (): void {
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team);
    $production = $project->production()->sole();
    $postgres = Projects::postgres($project);
    $web = Projects::image($project);
    $connection = resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    $variable = resolve(CreateVariable::class)->handle($project, $web, variableInput('GREETING', 'hello', $production), null, AuditActor::system());
    $secret = resolve(CreateVariable::class)->handle($project, $web, variableInput('API_TOKEN', 'one', $production, VariableType::Secret), null, AuditActor::system());
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());

    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    $store = resolve(VariableStore::class);

    // Stage one of everything.
    resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['deploy' => new DeploySettings(port: 8080), 'scaling' => new ScalingSettings(replicas: 3)]));
    resolve(UpdateVariable::class)->handle($variable, VariableUpdate::from(['value' => 'changed']), null, AuditActor::system());
    resolve(DeleteVariable::class)->handle($secret, AuditActor::system());
    resolve(CreateVariable::class)->handle($project, $web, variableInput('NEW_ONE', 'x', $production), null, AuditActor::system());
    resolve(DeleteConnection::class)->handle($connection, AuditActor::system());
    // Domains apply immediately (not staged), so discarding keeps them.
    resolve(AddDomain::class)->handle($instance, 'shop.example.com', AuditActor::system());
    $api = Projects::image($project, 'api');
    resolve(RemoveService::class)->handle(ServiceInstance::query()->where('service_id', $postgres->id)->sole(), AuditActor::system());

    expect(count(stagedPaths($production)))->toBeGreaterThanOrEqual(7)
        ->and(collect(stagedPaths($production))->filter(fn (string $path): bool => str_contains($path, '.domains.'))->all())->toBe([]);

    // Discard by path first: only the replicas.
    resolve(DiscardChanges::class)->handle($production, ["services.{$web->public_id}.scaling"], AuditActor::system());
    expect($instance->refresh()->scaling->replicas)->toBe(1)
        ->and($instance->deploy->port)->toBe(8080);

    $discarded = resolve(DiscardChanges::class)->handle($production, null, AuditActor::system());

    expect($discarded)->toBeGreaterThan(0)
        ->and(stagedPaths($production))->toBe([])
        ->and($instance->refresh()->deploy->port)->toBe(80)
        ->and($store->plaintext($project, $variable->refresh()))->toBe('hello')
        ->and($store->plaintext($project, Variable::query()->where('public_id', $secret->public_id)->sole()))->toBe('one')
        ->and(Variable::query()->where('key', 'NEW_ONE')->exists())->toBeFalse()
        ->and(Connection::query()->where('public_id', $connection->public_id)->exists())->toBeTrue()
        ->and(resolvedEnv($project, $production, $web)['DATABASE_URL'])->toStartWith('postgresql://')
        ->and(Domain::query()->where('hostname', 'shop.example.com')->exists())->toBeTrue()
        ->and(Service::query()->whereKey($api->id)->exists())->toBeFalse()
        ->and(ServiceInstance::query()->where('service_id', $postgres->id)->sole()->removed_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'environment.changes_discarded')->count())->toBe(2);
});

test('discard needs a deployed environment', function (): void {
    $project = Projects::create(Team::factory()->create());

    expect(fn () => resolve(DiscardChanges::class)->handle($project->production()->sole(), null, AuditActor::system()))->toThrow(DomainRuleViolation::class, 'never been deployed');
});

test('turning a secret into config requires permission to reveal secrets', function (): void {
    $team = Team::factory()->create();
    $project = Projects::create($team);
    $production = $project->production()->sole();
    $secret = resolve(CreateVariable::class)->handle($project, Projects::image($project), variableInput('API_TOKEN', 'tok_123', $production, VariableType::Secret), null, AuditActor::system());
    $downgrade = static fn (?User $user) => resolve(UpdateVariable::class)->handle($secret, VariableUpdate::from(['type' => VariableType::Config]), $user, AuditActor::system());

    // Developers can write variables but not reveal secrets (docs/09 R9).
    expect(fn () => $downgrade(memberOf($team, TeamRole::Developer)))->toThrow(DomainRuleViolation::class, 'reveal secrets')
        ->and(fn () => $downgrade(null))->toThrow(DomainRuleViolation::class, 'reveal secrets')
        ->and($secret->refresh()->type)->toBe(VariableType::Secret);

    $downgrade(memberOf($team, TeamRole::Admin));

    expect($secret->refresh()->type)->toBe(VariableType::Config);
});
