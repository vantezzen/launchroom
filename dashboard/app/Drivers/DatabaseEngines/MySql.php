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
        return '8.4';
    }

    public function image(string $version): ImageReference
    {
        return $this->images->for($this->engine(), $version);
    }

    public function port(): int
    {
        return 3306;
    }

    public function volumeMountPath(string $version): string
    {
        return '/var/lib/mysql';
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
        // MYSQL_PWD comes from the env (never argv).
        return ['mysqldump', '--single-transaction', '--routines', '--triggers', '--user=app', 'app'];
    }

    public function restoreCommand(): array
    {
        return ['mysql', '--user=app', 'app'];
    }

    public function dumpFileExtension(): string
    {
        return 'sql';
    }
}
