<?php

declare(strict_types=1);

use App\Data\Servers\LocationData;
use App\Support\Servers\ServerLocations;

test('time zones suggest their main city', function (): void {
    $berlin = ServerLocations::timezone('Europe/Berlin');

    expect($berlin)->toBeInstanceOf(LocationData::class)
        ->and($berlin?->label)->toBe('Berlin')
        ->and($berlin?->countryCode)->toBe('DE')
        ->and($berlin?->latitude)->toBeGreaterThan(52.0)->toBeLessThan(53.0)
        ->and($berlin?->source)->toBe(LocationData::SOURCE_TIMEZONE)
        ->and($berlin?->confirmed)->toBeFalse()
        ->and(ServerLocations::timezone('America/Argentina/Buenos_Aires')?->label)->toBe('Buenos Aires')
        ->and(ServerLocations::timezone('UTC'))->toBeNull()
        ->and(ServerLocations::timezone('Etc/GMT+2'))->toBeNull()
        ->and(ServerLocations::timezone('Europe/Atlantis'))->toBeNull();
});

test('region codes in labels and names suggest a data centre', function (string $value, ?string $label): void {
    expect(ServerLocations::region($value)?->label)->toBe($label);
})->with([
    ['fsn1', 'Falkenstein'],
    ['fsn1-dc14', 'Falkenstein'],
    ['fra-1', 'Frankfurt'],
    ['web.nbg1', 'Nuremberg'],
    ['us-east-1', 'N. Virginia'],
    ['eu-central-1a', 'Frankfurt'],
    ['NYC3', 'New York'],
    ['web-1', null],
    ['madrid-box', null],
    ['sea', 'Seattle'],
    ['server-2', null],
]);
