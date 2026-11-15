<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Data\Services\HealthcheckSettings;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\RemoteAccess\RemoteAccessProtocol;
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

    public function category(): DatabaseEngineCategory;

    /**
     * Configured versions, newest first.
     *
     * @return list<string>
     */
    public function versions(): array;

    /** The first configured version. */
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

    /** Where the server keeps its data files (Postgres: PGDATA). */
    public function dataDirectory(string $version): string;

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

    /** The exported variable holding one part of a connection, if the engine has it. */
    public function exportedKey(ConnectionPart $part): ?string;

    /**
     * Env names other images of this engine use for a connection part
     * (Compose imports rewrite consumers that repeat them, docs/17 §2.3).
     *
     * @return array<value-of<ConnectionPart>, list<string>>
     */
    public function imageEnvAliases(): array;

    /**
     * URL schemes that speak the engine's native protocol (its
     * ConnectionPart::NativePort) instead of the exported port's.
     *
     * @return list<string>
     */
    public function nativeSchemes(): array;

    /**
     * Query parameters a connection URL needs for the generated user
     * (MongoDB: `authSource=admin`).
     *
     * @return array<string, string>
     */
    public function urlQuery(): array;

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

    /** How long a stop waits before the kill (`WorkloadSpec.stop`, D42), as a Go duration. */
    public function stopTimeout(): string;

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

    /** The engine's command-line client in its image (launchroomd's dbengine.Client), if it has one. */
    public function client(): ?string;

    /** The PHP extension a connected PHP app needs (D71), or none. */
    public function phpExtension(): ?string;

    /**
     * The file extension of an export (docs/14 §12.5), as launchroomd writes
     * it (`backup.exportFormat`: MySQL dumps are gzipped).
     */
    public function exportFileExtension(): string;

    /** How a dump or URL is imported into it, or null: no imports (docs/14 §12). */
    public function importFormat(): ?DumpImport;

    /** Whether launchroomd restores its backups (docs/14 §7). */
    public function restorable(): bool;

    /** The first major version with point-in-time recovery (docs/14 §10), or null: none. */
    public function pointInTimeRecoveryFrom(): ?int;

    /** The protocol of temporary access (docs/08 §15), or null: none. */
    public function remoteAccess(): ?RemoteAccessProtocol;
}
