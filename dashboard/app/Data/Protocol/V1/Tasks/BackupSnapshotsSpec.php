<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Lists the snapshots of a repository with all of `tags` (protocol
 * BackupSnapshotsSpec).
 */
final class BackupSnapshotsSpec extends TaskSpec
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public BackupDestination $destination,
        public array $tags,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::BackupSnapshots;
    }
}
