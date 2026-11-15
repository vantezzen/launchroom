<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Restores a snapshot into a target (protocol RestoreSpec, docs/14 §5).
 */
final class RestoreSpec extends TaskSpec
{
    /**
     * @param  'database'|'volume'|'base_backup'  $kind
     * @param  'new_service'|'in_place'  $mode
     * @param  list<string>|null  $walTags
     */
    public function __construct(
        public string $kind,
        public string $snapshot,
        public BackupTarget $target,
        public BackupDestination $destination,
        public string $mode,
        public ?string $pointInTime = null,
        public ?array $walTags = null,
        public ?RestoreRuntime $runtime = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::Restore;
    }
}
