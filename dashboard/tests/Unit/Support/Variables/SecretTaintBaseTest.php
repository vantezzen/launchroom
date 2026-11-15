<?php

declare(strict_types=1);

use App\Data\Environments\EnvironmentTriggers;
use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use App\Data\Specs\EnvironmentMetaSpec;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Support\Variables\SecretTaint;

const TAINT_WEB = 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9W1';
const TAINT_DB = 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9D1';

/**
 * @param  array<string, VariableSpec>  $variables
 */
function taintService(string $id, string $slug, ServiceKind $kind, array $variables): ServiceSpec
{
    return new ServiceSpec(
        id: $id,
        instanceId: 'sin_'.substr($id, 4),
        slug: $slug,
        name: $slug,
        kind: $kind,
        parentId: null,
        source: $kind === ServiceKind::Database ? ['type' => 'database', 'engine' => 'postgres', 'version' => '18'] : ['type' => 'image', 'reference' => 'nginx:1'],
        icon: null,
        enabled: true,
        branchOverride: null,
        build: new BuildSettings,
        deploy: new DeploySettings(port: 3000),
        scaling: new ScalingSettings,
        variables: $variables,
    );
}

function taintVariable(string $id, string $key, VariableType $type, array $references = []): VariableSpec
{
    return new VariableSpec($id, $key, $type, 1, VariableExposure::classify($key), 'lr1:x', references: $references);
}

/**
 * @param  array<string, ServiceSpec>  $services
 * @param  array<string, string>  $sharedServices
 */
function taintSpec(EnvironmentKind $kind, array $services, array $sharedServices = []): EnvironmentSpec
{
    return new EnvironmentSpec(
        new EnvironmentMetaSpec('env_01J9Z8X7W6V5T4S3R2Q1P0N9N4', $kind->value, $kind->value, $kind, 'prj_01J9Z8X7W6V5T4S3R2Q1P0N9N0', 'shop', new EnvironmentTriggers),
        $services,
        sharedServices: $sharedServices,
    );
}

test('a shared preview database is tainted where the base environment has a secret, even if the preview has a config value', function (): void {
    $web = taintService(TAINT_WEB, 'web', ServiceKind::Image, ['var_1' => taintVariable('var_1', 'NEXT_PUBLIC_DB', VariableType::Config, ['db.TOKEN'])]);
    $preview = taintSpec(EnvironmentKind::Preview, [
        TAINT_WEB => $web,
        TAINT_DB => taintService(TAINT_DB, 'db', ServiceKind::Database, ['var_2' => taintVariable('var_2', 'TOKEN', VariableType::Config)]),
    ], [TAINT_DB => 'env_01J9Z8X7W6V5T4S3R2Q1P0N9N5']);
    $base = taintSpec(EnvironmentKind::Production, [
        TAINT_DB => taintService(TAINT_DB, 'db', ServiceKind::Database, ['var_3' => taintVariable('var_3', 'TOKEN', VariableType::Secret)]),
    ]);

    // Resolution swaps the shared service's scope for the base's: the reference reaches a secret.
    expect(SecretTaint::fromSpec($preview)->publicExposures(['web']))->toBe([])
        ->and(SecretTaint::fromSpec($preview, [TAINT_DB => $base])->isTainted('db', 'TOKEN'))->toBeTrue()
        ->and(SecretTaint::fromSpec($preview, [TAINT_DB => $base])->publicExposures(['web']))->toHaveCount(1);
});
