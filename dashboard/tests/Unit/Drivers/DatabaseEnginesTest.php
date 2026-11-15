<?php

declare(strict_types=1);

use App\Drivers\DatabaseEngines\ConnectionPart;
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

    expect($driver->defaultVersion())->toBe($driver->versions()[0])
        ->and($driver->health()->type)->toBe(HealthcheckType::Tcp)
        ->and(array_diff($driver->capabilities(), ['NET_BIND_SERVICE', 'CHOWN', 'SETUID', 'SETGID', 'DAC_OVERRIDE', 'FOWNER']))->toBe([])
        ->and($driver->stopSignal())->toBe($engine === DatabaseEngine::Postgres ? 'SIGINT' : 'SIGTERM')
        ->and($driver->stopTimeout())->toBe('60s');

    foreach ($driver->versions() as $version) {
        expect($driver->image($version)->isPinned())->toBeTrue()
            ->and($driver->image($version)->mirrored('127.0.0.1:5000/mirror'))->toMatch('/^127\.0\.0\.1:5000\/mirror\/docker\.io\/[a-z0-9\/._-]+@sha256:[a-f0-9]{64}$/');
    }
})->with(DatabaseEngine::cases());

test('postgres 18 mounts the parent of its versioned PGDATA', function (): void {
    $postgres = DatabaseEngines::fromConfig()->for(DatabaseEngine::Postgres);

    expect($postgres->volumeMountPath('18'))->toBe('/var/lib/postgresql')
        ->and($postgres->dataDirectory('18'))->toBe('/var/lib/postgresql/18/docker')
        ->and($postgres->volumeMountPath('17'))->toBe('/var/lib/postgresql/data')
        ->and($postgres->dataDirectory('17'))->toBe('/var/lib/postgresql/data')
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

test('each engine states what it can do (docs/14 §7, §10, §12; docs/08 §15)', function (): void {
    $engines = DatabaseEngines::fromConfig();
    $matrix = [];

    foreach (DatabaseEngine::cases() as $engine) {
        $driver = $engines->for($engine);
        $matrix[$engine->value] = [
            'import' => $driver->importFormat()?->formats,
            'restore' => $driver->restorable(),
            'pitr' => $driver->pointInTimeRecoveryFrom(),
            'access' => $driver->remoteAccess()?->value,
            'client' => $driver->client(),
            'export' => $driver->exportFileExtension(),
            'php' => $driver->phpExtension(),
        ];
    }

    expect($matrix)->toBe([
        'postgres' => ['import' => ['postgres_custom', 'postgres_sql'], 'restore' => true, 'pitr' => 15, 'access' => 'postgres', 'client' => 'psql', 'export' => 'dump', 'php' => 'pdo_pgsql'],
        // launchroomd gzips MySQL exports (backup.exportFormat).
        'mysql' => ['import' => ['mysql_sql'], 'restore' => true, 'pitr' => null, 'access' => null, 'client' => 'mysql', 'export' => 'sql.gz', 'php' => 'pdo_mysql'],
        'redis' => ['import' => null, 'restore' => false, 'pitr' => null, 'access' => 'redis', 'client' => 'redis-cli', 'export' => 'rdb', 'php' => 'redis'],
        'valkey' => ['import' => null, 'restore' => false, 'pitr' => null, 'access' => 'redis', 'client' => 'valkey-cli', 'export' => 'rdb', 'php' => 'redis'],
        'mongodb' => ['import' => ['mongodb_archive'], 'restore' => true, 'pitr' => null, 'access' => null, 'client' => null, 'export' => 'archive', 'php' => 'mongodb'],
        'clickhouse' => ['import' => null, 'restore' => true, 'pitr' => null, 'access' => null, 'client' => null, 'export' => 'zip', 'php' => null],
    ]);
});

test('every connection part an engine exports is one of its exported variables', function (DatabaseEngine $engine): void {
    $driver = DatabaseEngines::fromConfig()->for($engine);

    foreach (ConnectionPart::cases() as $part) {
        $key = $driver->exportedKey($part);
        expect($key === null || in_array($key, $driver->exportedKeys(), true))->toBeTrue("{$engine->value} {$part->value}");
    }

    expect($driver->exportedKey(ConnectionPart::Url))->not->toBeNull()
        ->and($driver->exportedKey(ConnectionPart::Host))->not->toBeNull()
        ->and($driver->exportedKey(ConnectionPart::Password))->not->toBeNull();

    foreach (array_keys($driver->imageEnvAliases()) as $part) {
        expect($driver->exportedKey(ConnectionPart::from($part)))->not->toBeNull();
    }
})->with(DatabaseEngine::cases());

test('import majors follow each engine\'s numbering', function (): void {
    $postgres = DatabaseEngines::fromConfig()->for(DatabaseEngine::Postgres)->importFormat();
    $mysql = DatabaseEngines::fromConfig()->for(DatabaseEngine::MySql)->importFormat();

    expect($postgres?->major('17.2'))->toBe('17')
        ->and($postgres?->major('9.6.24'))->toBe('9.6')
        ->and($mysql?->major('8.4.1'))->toBe('8.4');
});
