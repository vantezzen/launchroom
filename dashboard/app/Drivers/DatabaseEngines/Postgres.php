<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Data\Services\HealthcheckSettings;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\RemoteAccess\RemoteAccessProtocol;
use App\Enums\VariableType;
use App\Support\Images\ImageReference;

final readonly class Postgres implements DatabaseEngineDriver
{
    /** Postgres 18 images keep PGDATA in a versioned subdirectory and declare /var/lib/postgresql as the volume. */
    private const int VERSIONED_PGDATA_FROM = 18;

    public function __construct(
        private ConfiguredImages $images,
    ) {}

    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::Postgres;
    }

    public function versions(): array
    {
        return $this->images->versions($this->engine());
    }

    public function defaultVersion(): string
    {
        return $this->images->defaultVersion($this->engine());
    }

    public function image(string $version): ImageReference
    {
        return $this->images->for($this->engine(), $version);
    }

    public function port(): int
    {
        return 5432;
    }

    public function category(): DatabaseEngineCategory
    {
        return DatabaseEngineCategory::Relational;
    }

    public function volumeMountPath(string $version): string
    {
        return (int) $version >= self::VERSIONED_PGDATA_FROM ? '/var/lib/postgresql' : '/var/lib/postgresql/data';
    }

    public function dataDirectory(string $version): string
    {
        return (int) $version >= self::VERSIONED_PGDATA_FROM ? "/var/lib/postgresql/{$version}/docker" : '/var/lib/postgresql/data';
    }

    public function variables(): array
    {
        return [
            new DriverVariable('POSTGRES_USER', 'app'),
            new DriverVariable('POSTGRES_PASSWORD', '${{ secret(32) }}', VariableType::Secret),
            new DriverVariable('POSTGRES_DB', 'app'),
            new DriverVariable('PGHOST', '${{PRIVATE_HOST}}'),
            new DriverVariable('PGPORT', '5432'),
            new DriverVariable('PGUSER', '${{POSTGRES_USER}}'),
            new DriverVariable('PGPASSWORD', '${{POSTGRES_PASSWORD}}'),
            new DriverVariable('PGDATABASE', '${{POSTGRES_DB}}'),
            new DriverVariable('DATABASE_URL', 'postgresql://${{POSTGRES_USER}}:${{POSTGRES_PASSWORD}}@${{PRIVATE_HOST}}:5432/${{POSTGRES_DB}}'),
        ];
    }

    public function exportedKeys(): array
    {
        return ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGUSER', 'PGPASSWORD', 'PGDATABASE'];
    }

    public function exportedKey(ConnectionPart $part): ?string
    {
        return match ($part) {
            ConnectionPart::Url => 'DATABASE_URL',
            ConnectionPart::Host => 'PGHOST',
            ConnectionPart::Port => 'PGPORT',
            ConnectionPart::User => 'PGUSER',
            ConnectionPart::Password => 'PGPASSWORD',
            ConnectionPart::Database => 'PGDATABASE',
            ConnectionPart::NativePort => null,
        };
    }

    public function imageEnvAliases(): array
    {
        return [
            'user' => ['POSTGRES_USER', 'POSTGRESQL_USERNAME'],
            'password' => ['POSTGRES_PASSWORD', 'POSTGRESQL_PASSWORD'],
            'database' => ['POSTGRES_DB', 'POSTGRESQL_DATABASE'],
        ];
    }

    public function nativeSchemes(): array
    {
        return [];
    }

    public function urlQuery(): array
    {
        return [];
    }

    public function connectionVariables(ConnectionStyle $style, string $slug): array
    {
        return match ($style) {
            ConnectionStyle::Url => ['DATABASE_URL' => "\${{{$slug}.DATABASE_URL}}"],
            ConnectionStyle::Laravel => [
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => "\${{{$slug}.PGHOST}}",
                'DB_PORT' => "\${{{$slug}.PGPORT}}",
                'DB_DATABASE' => "\${{{$slug}.PGDATABASE}}",
                'DB_USERNAME' => "\${{{$slug}.PGUSER}}",
                'DB_PASSWORD' => "\${{{$slug}.PGPASSWORD}}",
            ],
            ConnectionStyle::Parts => [
                'PGHOST' => "\${{{$slug}.PGHOST}}",
                'PGPORT' => "\${{{$slug}.PGPORT}}",
                'PGUSER' => "\${{{$slug}.PGUSER}}",
                'PGPASSWORD' => "\${{{$slug}.PGPASSWORD}}",
                'PGDATABASE' => "\${{{$slug}.PGDATABASE}}",
            ],
        };
    }

    public function health(): HealthcheckSettings
    {
        return HealthcheckSettings::tcp(startPeriodSeconds: 60, intervalSeconds: 10, failureThreshold: 5);
    }

    public function capabilities(): array
    {
        // The entrypoint chowns the data dir and drops to the postgres user.
        return ['CHOWN', 'SETUID', 'SETGID', 'DAC_OVERRIDE', 'FOWNER'];
    }

    public function stopSignal(): string
    {
        // Fast shutdown: disconnects clients and writes a checkpoint. SIGTERM
        // (smart) would wait for every pooled connection to close.
        return 'SIGINT';
    }

    public function stopTimeout(): string
    {
        return '60s';
    }

    public function entrypoint(): ?array
    {
        return null;
    }

    public function args(string $version): ?array
    {
        return null;
    }

    public function client(): string
    {
        return 'psql';
    }

    public function phpExtension(): string
    {
        return 'pdo_pgsql';
    }

    public function exportFileExtension(): string
    {
        return 'dump';
    }

    public function importFormat(): DumpImport
    {
        return new DumpImport(
            engine: $this->engine(),
            formats: ['postgres_custom', 'postgres_sql'],
            formatsHint: 'pg_dump -Fc (custom) or plain SQL, optionally gzipped (.dump, .sql, .sql.gz)',
            urlSchemes: ['postgres', 'postgresql'],
            urlExample: 'postgres://user:password@db.example.com:5432/app',
            dumpTool: 'pg_dump',
            singleNumberMajorsFrom: 10,
            sqlFormat: 'postgres_sql',
        );
    }

    public function restorable(): bool
    {
        return true;
    }

    public function pointInTimeRecoveryFrom(): int
    {
        // basic_archive (WAL archiving without a shell command) ships with Postgres 15+.
        return 15;
    }

    public function remoteAccess(): RemoteAccessProtocol
    {
        return RemoteAccessProtocol::Postgres;
    }
}
