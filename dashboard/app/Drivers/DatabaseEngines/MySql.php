<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Data\Services\HealthcheckSettings;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\VariableType;
use App\Support\Images\ImageReference;

final readonly class MySql implements DatabaseEngineDriver
{
    public function __construct(
        private ConfiguredImages $images,
    ) {}

    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::MySql;
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
        return 3306;
    }

    public function category(): DatabaseEngineCategory
    {
        return DatabaseEngineCategory::Relational;
    }

    public function volumeMountPath(string $version): string
    {
        return '/var/lib/mysql';
    }

    public function dataDirectory(string $version): string
    {
        return $this->volumeMountPath($version);
    }

    public function variables(): array
    {
        return [
            new DriverVariable('MYSQL_USER', 'app'),
            new DriverVariable('MYSQL_PASSWORD', '${{ secret(32) }}', VariableType::Secret),
            new DriverVariable('MYSQL_ROOT_PASSWORD', '${{ secret(32) }}', VariableType::Secret),
            new DriverVariable('MYSQL_DATABASE', 'app'),
            new DriverVariable('MYSQLHOST', '${{PRIVATE_HOST}}'),
            new DriverVariable('MYSQLPORT', '3306'),
            new DriverVariable('DATABASE_URL', 'mysql://${{MYSQL_USER}}:${{MYSQL_PASSWORD}}@${{PRIVATE_HOST}}:3306/${{MYSQL_DATABASE}}'),
        ];
    }

    public function exportedKeys(): array
    {
        return ['DATABASE_URL', 'MYSQLHOST', 'MYSQLPORT', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_DATABASE'];
    }

    public function exportedKey(ConnectionPart $part): ?string
    {
        return match ($part) {
            ConnectionPart::Url => 'DATABASE_URL',
            ConnectionPart::Host => 'MYSQLHOST',
            ConnectionPart::Port => 'MYSQLPORT',
            ConnectionPart::User => 'MYSQL_USER',
            ConnectionPart::Password => 'MYSQL_PASSWORD',
            ConnectionPart::Database => 'MYSQL_DATABASE',
            ConnectionPart::NativePort => null,
        };
    }

    public function imageEnvAliases(): array
    {
        return [
            'user' => ['MYSQL_USER', 'MARIADB_USER'],
            'password' => ['MYSQL_PASSWORD', 'MARIADB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'MARIADB_ROOT_PASSWORD'],
            'database' => ['MYSQL_DATABASE', 'MARIADB_DATABASE'],
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
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => "\${{{$slug}.MYSQLHOST}}",
                'DB_PORT' => "\${{{$slug}.MYSQLPORT}}",
                'DB_DATABASE' => "\${{{$slug}.MYSQL_DATABASE}}",
                'DB_USERNAME' => "\${{{$slug}.MYSQL_USER}}",
                'DB_PASSWORD' => "\${{{$slug}.MYSQL_PASSWORD}}",
            ],
            ConnectionStyle::Parts => [
                'MYSQL_HOST' => "\${{{$slug}.MYSQLHOST}}",
                'MYSQL_PORT' => "\${{{$slug}.MYSQLPORT}}",
                'MYSQL_USER' => "\${{{$slug}.MYSQL_USER}}",
                'MYSQL_PASSWORD' => "\${{{$slug}.MYSQL_PASSWORD}}",
                'MYSQL_DATABASE' => "\${{{$slug}.MYSQL_DATABASE}}",
            ],
        };
    }

    public function health(): HealthcheckSettings
    {
        return HealthcheckSettings::tcp(startPeriodSeconds: 90, intervalSeconds: 10, failureThreshold: 6);
    }

    public function capabilities(): array
    {
        return ['CHOWN', 'SETUID', 'SETGID', 'DAC_OVERRIDE', 'FOWNER'];
    }

    public function stopSignal(): string
    {
        return 'SIGTERM';
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
        return 'mysql';
    }

    public function phpExtension(): string
    {
        return 'pdo_mysql';
    }

    public function exportFileExtension(): string
    {
        return 'sql.gz';
    }

    public function importFormat(): DumpImport
    {
        return new DumpImport(
            engine: $this->engine(),
            formats: ['mysql_sql'],
            formatsHint: 'mysqldump or mariadb-dump SQL, optionally gzipped (.sql, .sql.gz)',
            urlSchemes: ['mysql', 'mariadb'],
            urlExample: 'mysql://user:password@db.example.com:3306/app',
            sqlFormat: 'mysql_sql',
        );
    }

    public function restorable(): bool
    {
        return true;
    }

    public function pointInTimeRecoveryFrom(): null
    {
        return null;
    }

    public function remoteAccess(): null
    {
        return null;
    }
}
