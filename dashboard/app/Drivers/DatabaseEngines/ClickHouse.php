<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Data\Services\HealthcheckSettings;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\VariableType;
use App\Support\Images\ImageReference;

/**
 * ClickHouse (D47). The official image creates CLICKHOUSE_USER (with
 * CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT, so it can manage users like a
 * superuser) as a config file. CLICKHOUSE_DB is deliberately not set: the
 * entrypoint would create it through a temporary server that it then
 * `kill`s, which fails without CAP_KILL (root can't signal the clickhouse
 * user, D42) and aborts the start. So apps use the `default` database
 * (CLICKHOUSE_DATABASE, which the entrypoint ignores) or create their own
 * (Plausible does). The service's port is
 * the HTTP interface (8123: what most clients and Plausible use, and what
 * other servers reach over the mesh); the native protocol (9000) is
 * reachable from the same server.
 *
 * The entrypoint drops privileges with `clickhouse su` and execs the
 * server, so clickhouse-server is PID 1 and gets SIGTERM directly (D42).
 */
final readonly class ClickHouse implements DatabaseEngineDriver
{
    public function __construct(
        private ConfiguredImages $images,
    ) {}

    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::ClickHouse;
    }

    public function versions(): array
    {
        return $this->images->versions($this->engine());
    }

    public function defaultVersion(): string
    {
        return '26.8';
    }

    public function image(string $version): ImageReference
    {
        return $this->images->for($this->engine(), $version);
    }

    public function port(): int
    {
        return 8123;
    }

    public function volumeMountPath(string $version): string
    {
        return '/var/lib/clickhouse';
    }

    public function variables(): array
    {
        return [
            new DriverVariable('CLICKHOUSE_USER', 'app'),
            new DriverVariable('CLICKHOUSE_PASSWORD', '${{ secret(32) }}', VariableType::Secret),
            new DriverVariable('CLICKHOUSE_DATABASE', 'default'),
            new DriverVariable('CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT', '1'),
            new DriverVariable('CLICKHOUSE_HOST', '${{PRIVATE_HOST}}'),
            new DriverVariable('CLICKHOUSE_PORT', '8123'),
            new DriverVariable('CLICKHOUSE_NATIVE_PORT', '9000'),
            new DriverVariable('CLICKHOUSE_URL', 'http://${{CLICKHOUSE_USER}}:${{CLICKHOUSE_PASSWORD}}@${{PRIVATE_HOST}}:8123/${{CLICKHOUSE_DATABASE}}'),
        ];
    }

    public function exportedKeys(): array
    {
        return ['CLICKHOUSE_URL', 'CLICKHOUSE_HOST', 'CLICKHOUSE_PORT', 'CLICKHOUSE_NATIVE_PORT', 'CLICKHOUSE_USER', 'CLICKHOUSE_PASSWORD', 'CLICKHOUSE_DATABASE'];
    }

    public function connectionVariables(ConnectionStyle $style, string $slug): array
    {
        return match ($style) {
            ConnectionStyle::Url => ['CLICKHOUSE_URL' => "\${{{$slug}.CLICKHOUSE_URL}}"],
            ConnectionStyle::Laravel, ConnectionStyle::Parts => [
                'CLICKHOUSE_HOST' => "\${{{$slug}.CLICKHOUSE_HOST}}",
                'CLICKHOUSE_PORT' => "\${{{$slug}.CLICKHOUSE_PORT}}",
                'CLICKHOUSE_USER' => "\${{{$slug}.CLICKHOUSE_USER}}",
                'CLICKHOUSE_PASSWORD' => "\${{{$slug}.CLICKHOUSE_PASSWORD}}",
                'CLICKHOUSE_DATABASE' => "\${{{$slug}.CLICKHOUSE_DATABASE}}",
            ],
        };
    }

    public function health(): HealthcheckSettings
    {
        // The init phase listens on loopback only.
        return HealthcheckSettings::tcp(startPeriodSeconds: 60, intervalSeconds: 10, failureThreshold: 6);
    }

    public function capabilities(): array
    {
        // The entrypoint chowns its directories and drops to the clickhouse user.
        return ['CHOWN', 'SETUID', 'SETGID', 'DAC_OVERRIDE', 'FOWNER'];
    }

    public function stopSignal(): string
    {
        // Graceful: finishes running queries (up to shutdown_wait_unfinished) and flushes.
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
        // Native BACKUP into the data volume's backups/ directory, then streamed
        // out by launchroomd (docs/14 §11); CLICKHOUSE_PASSWORD comes from env.
        return ['clickhouse-client', '--user=app', '--query', 'BACKUP DATABASE app TO File(...)'];
    }

    public function restoreCommand(): array
    {
        return ['clickhouse-client', '--user=app', '--query', 'RESTORE DATABASE app FROM File(...)'];
    }

    public function dumpFileExtension(): string
    {
        return 'zip';
    }
}
