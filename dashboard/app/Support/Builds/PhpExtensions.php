<?php

declare(strict_types=1);

namespace App\Support\Builds;

use App\Data\Protocol\V1\PhpExtension;
use App\Enums\DatabaseEngine;

/**
 * PHP database drivers a build adds for the databases an app is connected to
 * (docs/04 §3.1, D71). A PHP app that was written for SQLite has no
 * `ext-pdo_pgsql` in composer.json; connected to Postgres it would fail at its
 * first query. launchroom knows the connections, so it asks Railpack for the
 * driver (`BuilderSpec.phpExtensions` → RAILPACK_PHP_EXTENSIONS) without
 * touching the repository. Dockerfile builds only get a hint.
 */
final class PhpExtensions
{
    /** The allow-list (protocol PhpExtensionName): names reach install-php-extensions. */
    public const array NAMES = ['pdo_pgsql', 'pgsql', 'pdo_mysql', 'mysqli', 'redis', 'mongodb'];

    /** Engines an extension may be "for" (protocol DatabaseEngine). */
    public const array ENGINES = ['postgres', 'mysql', 'mariadb', 'redis', 'valkey', 'mongodb', 'clickhouse'];

    /**
     * The driver extension of an engine (Laravel uses PDO for SQL and phpredis
     * by default, which also serves Valkey). ClickHouse has none.
     */
    public static function forEngine(DatabaseEngine $engine): ?string
    {
        return match ($engine) {
            DatabaseEngine::Postgres => 'pdo_pgsql',
            DatabaseEngine::MySql => 'pdo_mysql',
            DatabaseEngine::Redis, DatabaseEngine::Valkey => 'redis',
            DatabaseEngine::MongoDb => 'mongodb',
            DatabaseEngine::ClickHouse => null,
        };
    }

    /**
     * The extensions for a set of connected engines: unique, sorted by name,
     * each attributed to the first engine (by value) that needs it.
     *
     * @param  iterable<DatabaseEngine>  $engines
     * @return list<PhpExtension>
     */
    public static function forEngines(iterable $engines): array
    {
        $values = [];

        foreach ($engines as $engine) {
            $values[$engine->value] = $engine;
        }

        ksort($values);
        $extensions = [];

        foreach ($values as $engine) {
            $name = self::forEngine($engine);

            if ($name !== null && ! isset($extensions[$name])) {
                $extensions[$name] = new PhpExtension($name, $engine->value);
            }
        }

        ksort($extensions);

        return array_values($extensions);
    }

    /**
     * "the PostgreSQL driver (pdo_pgsql)".
     */
    public static function describe(PhpExtension $extension): string
    {
        $engine = DatabaseEngine::tryFrom($extension->for);

        return $engine === null
            ? "the PHP extension {$extension->name}"
            : "the {$engine->label()} driver ({$extension->name})";
    }

    /**
     * The staged-change label for a changed extension set, e.g. "adds the
     * PostgreSQL driver (pdo_pgsql)".
     *
     * @param  list<PhpExtension>  $before
     * @param  list<PhpExtension>  $after
     */
    public static function changeLabel(array $before, array $after): string
    {
        $beforeNames = array_map(static fn (PhpExtension $extension): string => $extension->name, $before);
        $afterNames = array_map(static fn (PhpExtension $extension): string => $extension->name, $after);
        $added = array_values(array_filter($after, static fn (PhpExtension $extension): bool => ! in_array($extension->name, $beforeNames, true)));
        $removed = array_values(array_filter($before, static fn (PhpExtension $extension): bool => ! in_array($extension->name, $afterNames, true)));
        $parts = [];

        if ($added !== []) {
            $parts[] = 'adds '.implode(', ', array_map(self::describe(...), $added));
        }

        if ($removed !== []) {
            $parts[] = 'drops '.implode(', ', array_map(self::describe(...), $removed));
        }

        return $parts === [] ? 'PHP extensions changed' : implode('; ', $parts);
    }
}
