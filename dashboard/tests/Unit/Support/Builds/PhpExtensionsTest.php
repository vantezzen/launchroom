<?php

declare(strict_types=1);

use App\Data\Protocol\V1\PhpExtension;
use App\Enums\DatabaseEngine;
use App\Support\Builds\PhpExtensions;

test('each engine maps to its PHP driver; ClickHouse has none', function (): void {
    $names = static fn (array $engines): array => array_map(static fn (PhpExtension $extension): string => "{$extension->name}:{$extension->for}", PhpExtensions::forEngines($engines));

    expect($names([DatabaseEngine::Postgres]))->toBe(['pdo_pgsql:postgres'])
        ->and($names([DatabaseEngine::MySql]))->toBe(['pdo_mysql:mysql'])
        ->and($names([DatabaseEngine::MongoDb, DatabaseEngine::ClickHouse]))->toBe(['mongodb:mongodb'])
        ->and($names([DatabaseEngine::Valkey, DatabaseEngine::Redis, DatabaseEngine::Postgres, DatabaseEngine::Postgres]))->toBe(['pdo_pgsql:postgres', 'redis:redis'])
        ->and($names([]))->toBe([]);

    foreach (DatabaseEngine::cases() as $engine) {
        $name = PhpExtensions::forEngine($engine);
        expect($name === null || in_array($name, PhpExtensions::NAMES, true))->toBeTrue()
            ->and(in_array($engine->value, PhpExtensions::ENGINES, true))->toBeTrue();
    }
});

test('change labels say what a rebuild adds and drops', function (): void {
    $pgsql = new PhpExtension('pdo_pgsql', 'postgres');
    $redis = new PhpExtension('redis', 'valkey');

    expect(PhpExtensions::changeLabel([], [$pgsql]))->toBe('adds the PostgreSQL driver (pdo_pgsql)')
        ->and(PhpExtensions::changeLabel([$pgsql, $redis], [$pgsql]))->toBe('drops the Valkey driver (redis)')
        ->and(PhpExtensions::changeLabel([$redis], [$pgsql]))->toBe('adds the PostgreSQL driver (pdo_pgsql); drops the Valkey driver (redis)');
});
