<?php

declare(strict_types=1);

use App\Data\Sessions\SessionCommandData;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\ConnectionStyle;
use App\Enums\DaemonFeature;
use App\Enums\DatabaseEngine;
use App\Support\Projects\DatabaseSuggestions;

test('MongoDB connects through the admin database with generated credentials (D47)', function (): void {
    $mongo = DatabaseEngines::fromConfig()->for(DatabaseEngine::MongoDb);
    $vars = collect($mongo->variables())->keyBy('key');

    expect($mongo->port())->toBe(27017)
        ->and($mongo->volumeMountPath('8.0'))->toBe('/data/db')
        ->and($mongo->versions())->toBe(['8.0', '7.0'])
        ->and($mongo->entrypoint())->toBeNull()
        ->and($vars['MONGO_URL']->value)->toContain('?authSource=admin')
        ->and($vars['MONGO_INITDB_ROOT_PASSWORD']->value)->toBe('${{ secret(32) }}')
        ->and($mongo->connectionVariables(ConnectionStyle::Laravel, 'db'))->toMatchArray(['DB_CONNECTION' => 'mongodb', 'MONGODB_URI' => '${{db.MONGO_URL}}'])
        ->and(implode(' ', $mongo->dumpCommand()))->not->toContain('password');
});

test('ClickHouse serves HTTP on its service port and exports the native port too', function (): void {
    $clickhouse = DatabaseEngines::fromConfig()->for(DatabaseEngine::ClickHouse);
    $vars = collect($clickhouse->variables())->keyBy('key');

    expect($clickhouse->port())->toBe(8123)
        ->and($clickhouse->volumeMountPath('26.8'))->toBe('/var/lib/clickhouse')
        ->and($clickhouse->versions())->toBe(['26.8', '26.3'])
        ->and($vars['CLICKHOUSE_URL']->value)->toStartWith('http://')
        ->and($vars['CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT']->value)->toBe('1')
        ->and($clickhouse->exportedKeys())->toContain('CLICKHOUSE_NATIVE_PORT');
});

test('new engines need an up-to-date launchroomd and say how the Data tab browses them', function (): void {
    expect(DatabaseEngine::MongoDb->daemonFeature())->toBe(DaemonFeature::MongoClickHouse)
        ->and(DatabaseEngine::ClickHouse->daemonFeature())->toBe(DaemonFeature::MongoClickHouse)
        ->and(DatabaseEngine::Postgres->daemonFeature())->toBeNull()
        ->and(DatabaseEngine::MongoDb->dataDialect())->toBe('mongo')
        ->and(DatabaseEngine::ClickHouse->dataDialect())->toBe('sql')
        ->and(DatabaseEngine::Valkey->dataDialect())->toBe('redis');

    foreach (DatabaseEngine::cases() as $engine) {
        expect($engine->tagline())->not->toBe('');
    }
});

test('the audit verb of a MongoDB command is its name, never the document', function (): void {
    $verb = fn (string $statement): string => SessionCommandData::from(['command' => 'query', 'statement' => $statement])->verb();

    expect($verb('{ "find": "users", "filter": {"secret": "x"} }'))->toBe('FIND')
        ->and($verb('select 1'))->toBe('SELECT');
});

test('env examples suggest MongoDB and ClickHouse', function (): void {
    $engines = fn (array $vars): array => array_map(static fn (array $s): DatabaseEngine => $s['engine'], DatabaseSuggestions::fromVariables($vars));

    expect($engines(['DATABASE_URL' => 'mongodb://localhost/app']))->toBe([DatabaseEngine::MongoDb])
        ->and($engines(['MONGODB_URI' => 'mongodb+srv://x']))->toBe([DatabaseEngine::MongoDb])
        ->and($engines(['DB_CONNECTION' => 'mongodb']))->toBe([DatabaseEngine::MongoDb])
        ->and($engines(['DATABASE_URL' => 'postgres://x', 'CLICKHOUSE_URL' => 'http://ch:8123']))->toBe([DatabaseEngine::Postgres, DatabaseEngine::ClickHouse]);
});
