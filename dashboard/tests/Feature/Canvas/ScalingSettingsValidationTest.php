<?php

declare(strict_types=1);

use App\Data\Services\ScalingSettings;
use App\Enums\PlacementStrategy;
use Illuminate\Validation\ValidationException;

test('scaling without labels or server rules is valid', function (): void {
    $scaling = ScalingSettings::validateAndCreate([
        'replicas' => 2,
        'cpus' => 1,
        'memoryMb' => 512,
        'placementLabels' => [],
        'strategy' => 'spread',
        'allowServers' => [],
        'denyServers' => [],
    ]);

    expect($scaling->replicas)->toBe(2)
        ->and($scaling->strategy)->toBe(PlacementStrategy::Spread)
        ->and($scaling->placementLabels)->toBe([])
        ->and($scaling->allowServers)->toBe([]);
});

test('placement lists must still be lists', function (): void {
    ScalingSettings::validateAndCreate([
        'replicas' => 1,
        'cpus' => 1,
        'memoryMb' => 512,
        'placementLabels' => [],
        'strategy' => 'spread',
        'allowServers' => 'srv_01',
        'denyServers' => [],
    ]);
})->throws(ValidationException::class);
