<?php

declare(strict_types=1);

use App\Data\Blueprints\BlueprintScaling;
use App\Data\Blueprints\BlueprintService;
use App\Data\Blueprints\ProjectBlueprint;
use App\Enums\ServiceKind;
use App\Enums\WorkloadCapability;
use App\Support\Blueprints\BlueprintValidator;

function bvImage(array $overrides = []): ProjectBlueprint
{
    return new ProjectBlueprint('shop', [new BlueprintService(...[
        'key' => 'web',
        'name' => 'web',
        'kind' => ServiceKind::Image,
        'image' => 'nginx:1.27',
        'port' => 80,
        ...$overrides,
    ])]);
}

test('scaling within ScalingSettings bounds is valid', function (): void {
    expect(BlueprintValidator::errors(bvImage(['scaling' => new BlueprintScaling(replicas: 64, cpus: 0.05, memoryMb: 16)])))->toBe([]);
});

test('scaling outside ScalingSettings bounds is refused per field', function (BlueprintScaling $scaling, string $field): void {
    expect(BlueprintValidator::errors(bvImage(['scaling' => $scaling])))->toBe(["web: {$field}."]);
})->with([
    'replicas' => [new BlueprintScaling(replicas: 65), 'replicas must be between 0 and 64'],
    'cpus' => [new BlueprintScaling(cpus: 0.01), 'CPUs must be between 0.05 and 1024'],
    'memory' => [new BlueprintScaling(memoryMb: 8), 'memory (MB) must be between 16 and 1048576'],
]);

test('capabilities come from the WorkloadCapability allow-list', function (): void {
    expect(BlueprintValidator::errors(bvImage(['capabilities' => WorkloadCapability::values()])))->toBe([])
        ->and(BlueprintValidator::errors(bvImage(['capabilities' => ['SYS_ADMIN']])))->toHaveCount(1);
});
