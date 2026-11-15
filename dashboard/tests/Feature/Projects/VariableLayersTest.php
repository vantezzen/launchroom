<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\SetSharedVariableUsage;
use App\Data\Audit\AuditActor;
use App\Data\Specs\VariableSpec;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Connection;
use App\Models\Environment;
use App\Models\Team;
use App\Models\Variable;
use App\Queries\CurrentEnvironmentSpec;
use App\Queries\ServiceVariablesView;
use App\Services\Canvas\ConnectionPreview;
use App\Services\Variables\SpecVariableResolver;
use App\Services\Variables\VariableStore;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

function layerInput(string $key, string $value, Environment $environment, VariableType $type = VariableType::Config): VariableInput
{
    return VariableInput::from(['key' => $key, 'value' => $value, 'type' => $type, 'targets' => [VariableTargetData::environment($environment->public_id)]]);
}

test('resolution, secret taint and the variables tab agree on which layer wins', function (): void {
    $team = Team::factory()->create();
    $project = Projects::create($team);
    $production = $project->production()->sole();
    $postgres = Projects::postgres($project);
    $web = Projects::image($project);
    $create = static fn (string $key, string $value, VariableType $type = VariableType::Config) => resolve(CreateVariable::class)
        ->handle($project, $web, layerInput($key, $value, $production, $type), null, AuditActor::system());

    // Shared secret < the service's own config value.
    $shared = resolve(CreateVariable::class)->handle($project, null, layerInput('TOKEN', 'shared-secret', $production, VariableType::Secret), null, AuditActor::system());
    resolve(SetSharedVariableUsage::class)->handle($shared, $web, true);
    $create('TOKEN', 'plain');
    // Connection-injected DATABASE_URL (references the database password) < the service's own value.
    resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    $create('DATABASE_URL', 'postgres://elsewhere');

    $spec = resolve(CurrentEnvironmentSpec::class)->get($production);
    $serviceSpec = $spec->services[$web->public_id];
    $resolver = resolve(SpecVariableResolver::class);
    $resolved = $resolver->resolve($project, $spec, $serviceSpec)->all();
    $taint = $resolver->taint($project, $spec);
    $rows = collect(resolve(ServiceVariablesView::class)->get($production, $web, memberOf($team, TeamRole::Developer))->variables);

    $keys = array_map(static fn (VariableSpec $variable): string => $variable->key, $spec->variablesInPrecedence($serviceSpec));

    expect(array_slice($keys, 0, 2))->toBe(['TOKEN', 'DATABASE_URL'])
        ->and(array_slice($keys, 2))->toEqualCanonicalizing(['TOKEN', 'DATABASE_URL'])
        ->and($resolved['TOKEN'])->toBe('plain')
        ->and($resolved['DATABASE_URL'])->toBe('postgres://elsewhere')
        ->and($taint->isTainted('web', 'TOKEN'))->toBeFalse()
        ->and($taint->isTainted('web', 'DATABASE_URL'))->toBeFalse()
        ->and($rows->first(static fn ($row): bool => $row->key === 'DATABASE_URL' && $row->connection !== null)?->overridden)->toBeTrue()
        ->and($rows->first(static fn ($row): bool => $row->key === 'DATABASE_URL' && $row->connection === null)?->overridden)->toBeFalse()
        ->and($rows->first(static fn ($row): bool => $row->key === 'TOKEN')?->overridden)->toBeFalse();
});

test('a connection that makes a browser-inlined variable carry a secret is refused when it is created', function (): void {
    $project = Projects::create(Team::factory()->create());
    $production = $project->production()->sole();
    $postgres = Projects::postgres($project);
    $web = Projects::image($project);

    // Broken until something injects DATABASE_URL: saving it alone is fine.
    resolve(CreateVariable::class)->handle($project, $web, layerInput('NEXT_PUBLIC_DB', '${{DATABASE_URL}}', $production), null, AuditActor::system());

    expect(fn () => resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'NEXT_PUBLIC_DB')
        ->and(Connection::query()->count())->toBe(0)
        ->and(Variable::query()->where('service_id', $web->id)->where('key', 'DATABASE_URL')->exists())->toBeFalse();
});

test('variable writes outside VariableStore::write are refused, so none skips the save-time check', function (): void {
    $project = Projects::create(Team::factory()->create());
    $web = Projects::image($project);

    expect(fn () => resolve(VariableStore::class)->create($project, $web, 'A', 'b', VariableType::Config, []))->toThrow(LogicException::class);
});

test('the connection preview shows exactly what creating the connection injects', function (): void {
    $project = Projects::create(Team::factory()->create());
    $postgres = Projects::postgres($project);
    $api = Projects::image($project, 'api', port: 8000);
    $web = Projects::image($project, 'web');
    $store = resolve(VariableStore::class);

    foreach ([[$postgres, ConnectionStyle::Laravel], [$api, ConnectionStyle::Url]] as [$to, $style]) {
        $preview = resolve(ConnectionPreview::class)->preview($web, $to, ConnectionMode::Private, $style);
        $connection = resolve(CreateConnection::class)->handle($web, $to, ConnectionMode::Private, $style, null, AuditActor::system());
        $created = Variable::query()->where('connection_id', $connection->id)->get()
            ->mapWithKeys(static fn (Variable $variable): array => [$variable->key => $store->plaintext($project, $variable)])->sortKeys()->all();
        $previewed = collect($preview->variables)->mapWithKeys(static fn ($variable): array => [$variable->key => $variable->value])->sortKeys()->all();

        expect($preview->error)->toBeNull()
            ->and($previewed)->toBe($created)
            ->and(resolve(ConnectionPreview::class)->preview($web, $to, ConnectionMode::Private, $style)->error)->toContain('already connected');
    }

    expect(resolve(ConnectionPreview::class)->preview($web, $web, null, null)->error)->toBe('A service can’t connect to itself.')
        ->and(fn () => resolve(CreateConnection::class)->handle($web, $web, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'A service can’t connect to itself.');
});
