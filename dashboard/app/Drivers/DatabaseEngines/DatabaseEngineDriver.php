<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Data\Services\HealthcheckSettings;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Support\Images\ImageReference;

/**
 * One database engine (docs/04 §10, docs/14 §7). Everything engine-specific
 * lives behind this interface: no per-engine `if` chains elsewhere
 * (docs/10 §2.1 "Drivers").
 *
 * Credentials are passed through env (never argv), so they don't show in
 * `ps`, and they are generated per environment (32+ URL-safe characters).
 */
interface DatabaseEngineDriver
{
    public function engine(): DatabaseEngine;

    /**
     * Supported major versions, newest first.
     *
     * @return list<string>
     */
    public function versions(): array;

    public function defaultVersion(): string;

    /**
     * The upstream image, digest-pinned (config `launchroom.database_engines`).
     * NodeStates render it through the local registry mirror.
     */
    public function image(string $version): ImageReference;

    public function port(): int;

    /**
     * Where the data volume is mounted. Version-aware: Postgres 18 keeps
     * PGDATA in /var/lib/postgresql/18/docker, so the parent is mounted.
     */
    public function volumeMountPath(string $version): string;

    /**
     * Variables created for every environment: generated credentials,
     * container configuration and the exported connection variables.
     *
     * @return list<DriverVariable>
     */
    public function variables(): array;

    /**
     * Variables other services may reference (`${{postgres.DATABASE_URL}}`).
     *
     * @return list<string>
     */
    public function exportedKeys(): array;

    /**
     * Variables a canvas connection injects into the consumer (docs/06 §3.2),
     * as references to $slug.
     *
     * @return array<string, string>
     */
    public function connectionVariables(ConnectionStyle $style, string $slug): array;

    public function health(): HealthcheckSettings;

    /**
     * Capabilities beyond cap-drop ALL; must be within the daemon's allow-list
     * (docs/03 §8).
     *
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * The engine's graceful-shutdown signal (`WorkloadSpec.stop`, D42): the
     * image's entrypoint execs the server as PID 1 without an init process,
     * so Docker's stop signal reaches it directly.
     *
     * @return 'SIGTERM'|'SIGINT'
     */
    public function stopSignal(): string;

    /**
     * ENTRYPOINT override rendered together with the stop (D42) when the
     * image's own ENTRYPOINT starts an init in front of the server; null
     * keeps the image's.
     *
     * @return list<string>|null
     */
    public function entrypoint(): ?array;

    /**
     * argv overriding the image CMD; null keeps the image default.
     *
     * @return list<string>|null
     */
    public function args(string $version): ?array;

    /**
     * Backup argv run in the database's network (docs/14 §7); credentials
     * come from the engine's env, never argv.
     *
     * @return list<string>
     */
    public function dumpCommand(): array;

    /**
     * @return list<string>
     */
    public function restoreCommand(): array;

    public function dumpFileExtension(): string;
}
