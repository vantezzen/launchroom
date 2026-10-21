<?php

declare(strict_types=1);

use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\HealthcheckType;
use App\Enums\VariableType;
use App\Support\Images\ImageReference;
use App\Support\Variables\GeneratedValues;
use App\Support\Variables\VariableReferences;
use App\Support\Variables\VariableResolver;

test('every engine has digest-pinned images for its versions, a TCP probe and allowed capabilities', function (DatabaseEngine $engine): void {
    $driver = DatabaseEngines::fromConfig()->for($engine);

    expect($driver->versions())->toContain($driver->defaultVersion())
        ->and($driver->health()->type)->toBe(HealthcheckType::Tcp)
        ->and(array_diff($driver->capabilities(), ['NET_BIND_SERVICE', 'CHOWN', 'SETUID', 'SETGID', 'DAC_OVERRIDE', 'FOWNER']))->toBe([])
        ->and($driver->dumpCommand())->not->toBeEmpty()
        ->and($driver->stopSignal())->toBe($engine === DatabaseEngine::Postgres ? 'SIGINT' : 'SIGTERM');

    foreach ($driver->versions() as $version) {
        expect($driver->image($version)->isPinned())->toBeTrue()
            ->and($driver->image($version)->mirrored('127.0.0.1:5000/mirror'))->toMatch('/^127\.0\.0\.1:5000\/mirror\/docker\.io\/[a-z0-9\/._-]+@sha256:[a-f0-9]{64}$/');
    }
})->with(DatabaseEngine::cases());

test('postgres 18 mounts the parent of its versioned PGDATA', function (): void {
    $postgres = DatabaseEngines::fromConfig()->for(DatabaseEngine::Postgres);

    expect($postgres->volumeMountPath('18'))->toBe('/var/lib/postgresql')
        ->and($postgres->volumeMountPath('17'))->toBe('/var/lib/postgresql/data')
        ->and($postgres->port())->toBe(5432);
});

test('generated credentials are long, URL-safe secrets, and exported variables resolve', function (DatabaseEngine $engine): void {
    $driver = DatabaseEngines::fromConfig()->for($engine);
    $values = ['PRIVATE_HOST' => 'db'];
    $secrets = 0;

    foreach ($driver->variables() as $variable) {
        expect(VariableReferences::invalid($variable->value))->toBe([]);
        $value = GeneratedValues::evaluate($variable->value);

        if ($variable->type === VariableType::Secret) {
            $secrets++;
            expect($value)->toMatch('/^[A-Za-z0-9_-]{32,}$/');
        }

        $values[$variable->key] = $value;
    }

    $resolved = new VariableResolver(['db' => $values])->resolve('db');

    expect($secrets)->toBeGreaterThan(0);

    foreach ($driver->exportedKeys() as $key) {
        expect($resolved->get($key))->not->toBeNull($key);
    }
})->with(DatabaseEngine::cases());

test('connection variables are references to the provider, per framework style', function (): void {
    $postgres = DatabaseEngines::fromConfig()->for(DatabaseEngine::Postgres);

    expect($postgres->connectionVariables(ConnectionStyle::Url, 'db'))->toBe(['DATABASE_URL' => '${{db.DATABASE_URL}}'])
        ->and($postgres->connectionVariables(ConnectionStyle::Laravel, 'db'))->toMatchArray(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '${{db.PGHOST}}', 'DB_PASSWORD' => '${{db.PGPASSWORD}}'])
        ->and(DatabaseEngines::fromConfig()->for(DatabaseEngine::Redis)->connectionVariables(ConnectionStyle::Url, 'cache'))->toBe(['REDIS_URL' => '${{cache.REDIS_URL}}']);
});

test('redis-compatible engines never put the password in argv', function (DatabaseEngine $engine): void {
    $args = DatabaseEngines::fromConfig()->for($engine)->args('8') ?? [];

    expect(implode(' ', $args))->toContain('$REDIS_PASSWORD')
        ->and(implode(' ', $args))->toContain('docker-entrypoint.sh');
})->with([DatabaseEngine::Redis, DatabaseEngine::Valkey]);

test('unknown versions are refused', function (): void {
    expect(fn (): ImageReference => DatabaseEngines::fromConfig()->for(DatabaseEngine::Postgres)->image('9'))->toThrow(InvalidArgumentException::class);
});
