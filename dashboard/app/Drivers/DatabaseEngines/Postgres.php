<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Data\Services\HealthcheckSettings;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\VariableType;
use App\Support\Images\ImageReference;

final readonly class Postgres implements DatabaseEngineDriver
{
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
        return '18';
    }

    public function image(string $version): ImageReference
    {
        return $this->images->for($this->engine(), $version);
    }

    public function port(): int
    {
        return 5432;
    }

    public function volumeMountPath(string $version): string
    {
        // Postgres 18 images keep PGDATA in a versioned subdirectory
        // (/var/lib/postgresql/18/docker) and declare /var/lib/postgresql as
        // the volume; older images use /var/lib/postgresql/data.
        return (int) $version >= 18 ? '/var/lib/postgresql' : '/var/lib/postgresql/data';
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

    public function entrypoint(): ?array
    {
        return null;
    }

    public function args(string $version): ?array
    {
        return null;
    }

    public function dumpCommand(): array
    {
        return ['pg_dump', '--format=custom', '--no-owner', '--no-privileges'];
    }

    public function restoreCommand(): array
    {
        return ['pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges', '--dbname=app'];
    }

    public function dumpFileExtension(): string
    {
        return 'dump';
    }
}
