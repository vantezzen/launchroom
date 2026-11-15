<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

/**
 * What `daemon_tasks.seal` references: the secrets a task needs, resolved
 * and sealed to the leasing server when it is leased (docs/03 §7,
 * App\Services\Tasks\TaskSecrets). Nothing secret is stored here.
 *
 * - `env` → `spec.env`;
 * - `cloneToken` / `sourceCloneToken` → the repository's credential in the
 *   spec / in `spec.source` (inventory tasks);
 * - `sessionKey` / `accessPassword` → the key of a session, the password of
 *   a temporary access;
 * - `destination` → `spec.destination.credentials` of a backup destination;
 * - `restoreRuntimeEnv` → `spec.runtime.env`: POSTGRES_USER/PASSWORD/DB of
 *   the database a point-in-time restore replays into.
 */
final class TaskSeal extends Data
{
    public function __construct(
        public ?EnvSeal $env = null,
        public ?CloneSeal $cloneToken = null,
        public ?CloneSeal $sourceCloneToken = null,
        public ?SessionKeySeal $sessionKey = null,
        public ?AccessPasswordSeal $accessPassword = null,
        /** backup_destinations.id */
        public ?int $destination = null,
        /** service_instances.id of the database a point-in-time restore replays into */
        public ?int $restoreRuntimeEnv = null,
    ) {}
}
