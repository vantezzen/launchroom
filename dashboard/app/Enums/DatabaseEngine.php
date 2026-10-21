<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Database engines with a driver in App\Drivers\DatabaseEngines (docs/04 §10).
 */
enum DatabaseEngine: string
{
    case Postgres = 'postgres';
    case MySql = 'mysql';
    case Redis = 'redis';
    case Valkey = 'valkey';
    case MongoDb = 'mongodb';
    case ClickHouse = 'clickhouse';

    public function label(): string
    {
        return match ($this) {
            self::Postgres => 'PostgreSQL',
            self::MySql => 'MySQL',
            self::Redis => 'Redis',
            self::Valkey => 'Valkey',
            self::MongoDb => 'MongoDB',
            self::ClickHouse => 'ClickHouse',
        };
    }

    /** One line for the "Add database" engine cards. */
    public function tagline(): string
    {
        return match ($this) {
            self::Postgres => 'Relational, the default',
            self::MySql => 'Relational',
            self::Redis => 'Cache and queues',
            self::Valkey => 'Redis-compatible',
            self::MongoDb => 'Documents',
            self::ClickHouse => 'Analytics',
        };
    }

    /**
     * The launchroomd feature backups, restores, verifications and Data
     * sessions of this engine need (null: every launchroomd has it). Old
     * daemons refuse the engine name in task specs.
     */
    public function daemonFeature(): ?DaemonFeature
    {
        return match ($this) {
            self::MongoDb, self::ClickHouse => DaemonFeature::MongoClickHouse,
            default => null,
        };
    }

    /**
     * How the Data tab browses and queries it (docs/03 §11.4).
     *
     * @return 'sql'|'redis'|'mongo'
     */
    public function dataDialect(): string
    {
        return match ($this) {
            self::Postgres, self::MySql, self::ClickHouse => 'sql',
            self::Redis, self::Valkey => 'redis',
            self::MongoDb => 'mongo',
        };
    }
}
