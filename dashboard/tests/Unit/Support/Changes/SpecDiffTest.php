<?php

declare(strict_types=1);

use App\Data\Environments\EnvironmentTriggers;
use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\HealthcheckSettings;
use App\Data\Services\ScalingSettings;
use App\Data\Specs\ChangeItem;
use App\Data\Specs\ConnectionSpec;
use App\Data\Specs\DomainSpec;
use App\Data\Specs\EnvironmentMetaSpec;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Data\Specs\VolumeSpec;
use App\Enums\ChangeEffect;
use App\Enums\ChangeKind;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DomainKind;
use App\Enums\EnvironmentKind;
use App\Enums\PromotionClass;
use App\Enums\ServiceKind;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Support\Changes\FieldMeta;
use App\Support\Changes\SpecDiff;

function specService(string $id, string $slug, ServiceKind $kind = ServiceKind::App, array $overrides = []): ServiceSpec
{
    return new ServiceSpec(...[
        'id' => $id,
        'instanceId' => 'sin_'.substr($id, 4),
        'slug' => $slug,
        'name' => $slug,
        'kind' => $kind,
        'parentId' => null,
        'source' => match ($kind) {
            ServiceKind::Database => ['type' => 'database', 'engine' => 'postgres', 'version' => '18'],
            ServiceKind::Image => ['type' => 'image', 'reference' => 'nginx:1'],
            default => ['type' => 'git', 'repositoryId' => 1, 'fullName' => 'o/r', 'installationId' => null, 'rootDirectory' => ''],
        },
        'icon' => null,
        'enabled' => true,
        'branchOverride' => null,
        'build' => new BuildSettings,
        'deploy' => new DeploySettings(port: 3000),
        'scaling' => new ScalingSettings,
        ...$overrides,
    ]);
}

function specVariable(string $id, string $key, int $version = 1, VariableType $type = VariableType::Config, array $references = [], ?VariableExposure $exposure = null, ?string $connectionId = null): VariableSpec
{
    return new VariableSpec($id, $key, $type, $version, $exposure ?? VariableExposure::classify($key), 'lr1:cipher-'.$id.'-'.$version, references: $references, connectionId: $connectionId);
}

/**
 * @param  array<string, ServiceSpec>  $services
 */
function envSpec(array $services, array $connections = [], array $shared = [], ?EnvironmentTriggers $triggers = null): EnvironmentSpec
{
    return new EnvironmentSpec(
        new EnvironmentMetaSpec('env_01J9Z8X7W6V5T4S3R2Q1P0N9N4', 'production', 'production', EnvironmentKind::Production, 'prj_01J9Z8X7W6V5T4S3R2Q1P0N9N0', 'shop', $triggers ?? new EnvironmentTriggers),
        $services,
        $connections,
        $shared,
    );
}

const SPEC_WEB = 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9W1';
const SPEC_API = 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9A1';
const SPEC_DB = 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9D1';

/**
 * @return array<string, string> path => effect
 */
function effectsByPath(EnvironmentSpec $before, EnvironmentSpec $after): array
{
    $items = [];

    foreach (SpecDiff::between($before, $after)->items as $item) {
        $items[$item->path] = $item->effect->value;
    }

    return $items;
}

test('identical specs have no changes', function (): void {
    $spec = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web')]);

    expect(SpecDiff::between($spec, $spec)->isEmpty())->toBeTrue();
});

test('the first deploy makes every enabled service new', function (): void {
    $changes = SpecDiff::between(null, envSpec([SPEC_WEB => specService(SPEC_WEB, 'web'), SPEC_DB => specService(SPEC_DB, 'db', ServiceKind::Database, ['enabled' => false])]));

    expect($changes->effectFor(SPEC_WEB))->toBe(ChangeEffect::New)
        ->and($changes->effectFor(SPEC_DB))->toBe(ChangeEffect::None)
        ->and($changes->items)->toHaveCount(2);
});

test('added and removed services', function (): void {
    $before = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web')]);
    $after = envSpec([SPEC_API => specService(SPEC_API, 'api')]);
    $changes = SpecDiff::between($before, $after);

    expect($changes->effectFor(SPEC_API))->toBe(ChangeEffect::New)
        ->and($changes->effectFor(SPEC_WEB))->toBe(ChangeEffect::Remove)
        ->and(array_map(static fn (ChangeItem $item): string => $item->kind->value, $changes->items))->toBe(['added', 'removed']);
});

test('enabling and disabling are new / remove', function (): void {
    $on = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web')]);
    $off = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['enabled' => false])]);

    expect(SpecDiff::between($on, $off)->effectFor(SPEC_WEB))->toBe(ChangeEffect::Remove)
        ->and(SpecDiff::between($off, $on)->effectFor(SPEC_WEB))->toBe(ChangeEffect::New);
});

test('field effects follow the build/runtime classification', function (array $overrides, string $path, ChangeEffect $effect): void {
    $before = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web')]);
    $after = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: $overrides)]);
    $changes = SpecDiff::between($before, $after);

    expect(effectsByPath($before, $after))->toBe([$path => $effect->value])
        ->and($changes->effectFor(SPEC_WEB))->toBe($effect);
})->with([
    'builder' => [['build' => new BuildSettings(builder: 'dockerfile')], 'services.'.SPEC_WEB.'.build.builder', ChangeEffect::Rebuild],
    'railpack version' => [['build' => new BuildSettings(railpackVersion: '0.9.0')], 'services.'.SPEC_WEB.'.build.railpackVersion', ChangeEffect::Rebuild],
    'watch paths' => [['build' => new BuildSettings(watchPaths: ['apps/web'])], 'services.'.SPEC_WEB.'.build.watchPaths', ChangeEffect::None],
    'source' => [['source' => ['type' => 'git', 'repositoryId' => 1, 'fullName' => 'o/r', 'installationId' => null, 'rootDirectory' => 'apps/web']], 'services.'.SPEC_WEB.'.source', ChangeEffect::Rebuild],
    'branch override' => [['branchOverride' => 'develop'], 'services.'.SPEC_WEB.'.branchOverride', ChangeEffect::Rebuild],
    'start command' => [['deploy' => new DeploySettings(port: 3000, startCommand: ['node', 'server.js'])], 'services.'.SPEC_WEB.'.deploy.startCommand', ChangeEffect::Restart],
    'port' => [['deploy' => new DeploySettings(port: 8080)], 'services.'.SPEC_WEB.'.deploy.port', ChangeEffect::Restart],
    'healthcheck' => [['deploy' => new DeploySettings(port: 3000, healthcheck: new HealthcheckSettings(path: '/up'))], 'services.'.SPEC_WEB.'.deploy.healthcheck', ChangeEffect::Restart],
    'seed command' => [['deploy' => new DeploySettings(port: 3000, seedCommand: ['php', 'artisan', 'db:seed'])], 'services.'.SPEC_WEB.'.deploy.seedCommand', ChangeEffect::None],
    'replicas' => [['scaling' => new ScalingSettings(replicas: 2)], 'services.'.SPEC_WEB.'.scaling.replicas', ChangeEffect::Restart],
    'name' => [['name' => 'Web app'], 'services.'.SPEC_WEB.'.name', ChangeEffect::None],
    'icon' => [['icon' => 'globe'], 'services.'.SPEC_WEB.'.icon', ChangeEffect::None],
    'domain added' => [['domains' => ['shop.com' => new DomainSpec('dom_01J9Z8X7W6V5T4S3R2Q1P0N9X1', 'shop.com', DomainKind::Custom)]], 'services.'.SPEC_WEB.'.domains.shop.com', ChangeEffect::Route],
    'volume added' => [['volumes' => ['vol_01J9Z8X7W6V5T4S3R2Q1P0N9X1' => new VolumeSpec('vol_01J9Z8X7W6V5T4S3R2Q1P0N9X1', '/data')]], 'services.'.SPEC_WEB.'.volumes.vol_01J9Z8X7W6V5T4S3R2Q1P0N9X1', ChangeEffect::Restart],
]);

test('build-affecting changes of never-built kinds restart instead of rebuilding', function (): void {
    $before = envSpec([SPEC_DB => specService(SPEC_DB, 'db', ServiceKind::Database)]);
    $after = envSpec([SPEC_DB => specService(SPEC_DB, 'db', ServiceKind::Database, ['source' => ['type' => 'database', 'engine' => 'postgres', 'version' => '17']])]);

    expect(SpecDiff::between($before, $after)->effectFor(SPEC_DB))->toBe(ChangeEffect::Restart);
});

test('the strongest effect per service wins', function (): void {
    $before = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web')]);
    $after = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['name' => 'x', 'scaling' => new ScalingSettings(replicas: 3), 'build' => new BuildSettings(builder: 'static')])]);

    expect(SpecDiff::between($before, $after)->effectFor(SPEC_WEB))->toBe(ChangeEffect::Rebuild);
});

test('variables: runtime values restart, build-scoped values rebuild, never carrying values', function (): void {
    $runtime = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9R1', 'API_KEY', type: VariableType::Secret);
    $public = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9R2', 'NEXT_PUBLIC_URL');
    $before = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['variables' => [$runtime->id => $runtime, $public->id => $public]])]);

    $rotated = specVariable($runtime->id, 'API_KEY', 2, VariableType::Secret);
    $after = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['variables' => [$runtime->id => $rotated, $public->id => $public]])]);
    $changes = SpecDiff::between($before, $after);

    expect($changes->effectFor(SPEC_WEB))->toBe(ChangeEffect::Restart)
        ->and($changes->items[0]->before)->toBeNull()
        ->and($changes->items[0]->after)->toBeNull()
        ->and($changes->items[0]->promotion)->toBe(PromotionClass::Never)
        ->and(json_encode($changes->toArray()))->not->toContain('cipher');

    $publicChanged = specVariable($public->id, 'NEXT_PUBLIC_URL', 2);
    $after = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['variables' => [$runtime->id => $runtime, $public->id => $publicChanged]])]);

    expect(SpecDiff::between($before, $after)->effectFor(SPEC_WEB))->toBe(ChangeEffect::Rebuild);
});

test('variable additions, removals, renames and type changes are items', function (): void {
    $a = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9R1', 'A');
    $b = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9R2', 'B');
    $before = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['variables' => [$a->id => $a]])]);
    $after = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['variables' => [$b->id => $b]])]);
    $renamed = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['variables' => [$a->id => specVariable($a->id, 'A2')]])]);

    expect(array_map(static fn (ChangeItem $item): ChangeKind => $item->kind, SpecDiff::between($before, $after)->items))->toBe([ChangeKind::Added, ChangeKind::Removed])
        ->and(SpecDiff::between($before, $renamed)->items[0]->promotion)->toBe(PromotionClass::Structural);
});

test('a referenced service changing restarts its consumers, transitively', function (): void {
    $password = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9P1', 'POSTGRES_PASSWORD', 1, VariableType::Secret);
    $apiUrl = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9P2', 'DATABASE_URL', references: ['db.DATABASE_URL']);
    $webUrl = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9P3', 'NEXT_PUBLIC_API', references: ['api.PUBLIC_URL']);

    $services = static fn (VariableSpec $pw): array => [
        SPEC_DB => specService(SPEC_DB, 'db', ServiceKind::Database, ['variables' => [$pw->id => $pw]]),
        SPEC_API => specService(SPEC_API, 'api', overrides: ['variables' => [$apiUrl->id => $apiUrl]]),
        SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['variables' => [$webUrl->id => $webUrl]]),
    ];

    $changes = SpecDiff::between(envSpec($services($password)), envSpec($services(specVariable($password->id, 'POSTGRES_PASSWORD', 2, VariableType::Secret))));

    expect($changes->effectFor(SPEC_DB))->toBe(ChangeEffect::Restart)
        ->and($changes->effectFor(SPEC_API))->toBe(ChangeEffect::Restart)
        // web reads api's URL at build time (NEXT_PUBLIC_*): rebuild.
        ->and($changes->effectFor(SPEC_WEB))->toBe(ChangeEffect::Rebuild)
        // Propagation adds effects, not items.
        ->and($changes->items)->toHaveCount(1);
});

test('shared variables affect only services that use or reference them', function (): void {
    $shared = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9S1', 'REGION');
    $other = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9S2', 'UNUSED');
    $ref = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9S3', 'R', references: ['shared.REGION']);

    $services = [
        SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['sharedVariableIds' => [$shared->id]]),
        SPEC_API => specService(SPEC_API, 'api', overrides: ['variables' => [$ref->id => $ref]]),
        SPEC_DB => specService(SPEC_DB, 'db', ServiceKind::Database),
    ];

    $changes = SpecDiff::between(
        envSpec($services, shared: [$shared->id => $shared, $other->id => $other]),
        envSpec($services, shared: [$shared->id => specVariable($shared->id, 'REGION', 2), $other->id => $other]),
    );

    expect($changes->effectFor(SPEC_WEB))->toBe(ChangeEffect::Restart)
        ->and($changes->effectFor(SPEC_API))->toBe(ChangeEffect::Restart)
        ->and($changes->effectFor(SPEC_DB))->toBe(ChangeEffect::None)
        ->and($changes->items[0]->path)->toBe('sharedVariables.'.$shared->id);
});

test('connections restart the consumer', function (): void {
    $connection = new ConnectionSpec('con_01J9Z8X7W6V5T4S3R2Q1P0N9C1', SPEC_API, SPEC_DB, ConnectionMode::Private, ConnectionStyle::Laravel);
    $owned = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9C2', 'DB_HOST', references: ['db.PGHOST'], connectionId: $connection->id);
    $before = envSpec([SPEC_API => specService(SPEC_API, 'api'), SPEC_DB => specService(SPEC_DB, 'db', ServiceKind::Database)]);
    $after = envSpec([
        SPEC_API => specService(SPEC_API, 'api', overrides: ['connectionVariables' => [$owned->id => $owned]]),
        SPEC_DB => specService(SPEC_DB, 'db', ServiceKind::Database),
    ], [$connection->id => $connection]);

    $changes = SpecDiff::between($before, $after);

    expect($changes->effectFor(SPEC_API))->toBe(ChangeEffect::Restart)
        ->and($changes->effectFor(SPEC_DB))->toBe(ChangeEffect::None)
        ->and(array_map(static fn (ChangeItem $item): string => $item->path, $changes->items))->toBe([
            'services.'.SPEC_API.'.connectionVariables.'.$owned->id,
            'connections.'.$connection->id,
        ])
        ->and($changes->items[1]->label)->toBe('connection api → db added');
});

test('trigger changes are recorded without any effect', function (): void {
    $before = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web')]);
    $after = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web')], triggers: new EnvironmentTriggers(branch: 'release/*'));
    $changes = SpecDiff::between($before, $after);

    expect(effectsByPath($before, $after))->toBe(['environment.triggers' => 'none'])
        ->and($changes->items[0]->promotion)->toBe(PromotionClass::EnvironmentSpecific)
        ->and($changes->services)->toBe([]);
});

test('itemsUnder selects by path prefix', function (): void {
    $before = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web')]);
    $after = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['name' => 'x', 'scaling' => new ScalingSettings(replicas: 2)])]);
    $changes = SpecDiff::between($before, $after);

    expect($changes->itemsUnder(['services.'.SPEC_WEB]))->toHaveCount(2)
        ->and($changes->itemsUnder(['services.'.SPEC_WEB.'.scaling']))->toHaveCount(1)
        ->and($changes->itemsUnder(['services.'.SPEC_WEB.'.scal']))->toHaveCount(0);
});

test('every settings property declares its effect and promotion class', function (string $class): void {
    $properties = array_map(static fn (ReflectionProperty $property): string => $property->getName(), new ReflectionClass($class)->getProperties(ReflectionProperty::IS_PUBLIC));

    expect(array_keys(FieldMeta::of($class)))->toBe($properties);
})->with([BuildSettings::class, DeploySettings::class, ScalingSettings::class, EnvironmentTriggers::class]);

test('promotion classes follow docs/06 §6.2', function (): void {
    expect(FieldMeta::of(ScalingSettings::class)['replicas'][1])->toBe(PromotionClass::EnvironmentSpecific)
        ->and(FieldMeta::of(DeploySettings::class)['startCommand'][1])->toBe(PromotionClass::Structural)
        ->and(FieldMeta::of(EnvironmentTriggers::class)['branch'][1])->toBe(PromotionClass::EnvironmentSpecific)
        ->and(FieldMeta::promotion(VariableSpec::class, 'ciphertext'))->toBe(PromotionClass::Never)
        ->and(FieldMeta::promotion(VariableSpec::class, 'key'))->toBe(PromotionClass::Structural);
});

test('the diff is deterministic and survives a JSON round trip of the spec', function (): void {
    $variable = specVariable('var_01J9Z8X7W6V5T4S3R2Q1P0N9R1', 'A');
    $spec = envSpec([SPEC_WEB => specService(SPEC_WEB, 'web', overrides: ['variables' => [$variable->id => $variable], 'domains' => ['a.com' => new DomainSpec('dom_01J9Z8X7W6V5T4S3R2Q1P0N9X1', 'a.com', DomainKind::Custom)]])]);
    $roundTripped = EnvironmentSpec::from(json_decode((string) json_encode($spec->toArray()), true));

    expect(SpecDiff::between($spec, $roundTripped)->isEmpty())->toBeTrue()
        ->and($roundTripped->services[SPEC_WEB]->variables[$variable->id]->key)->toBe('A');
});
