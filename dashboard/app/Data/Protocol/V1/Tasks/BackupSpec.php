<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Takes one restic snapshot (protocol BackupSpec, docs/14 §2).
 */
final class BackupSpec extends TaskSpec
{
    /**
     * @param  'database'|'volume'|'base_backup'  $kind
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $kind,
        public BackupTarget $target,
        public BackupDestination $destination,
        public array $tags,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::Backup;
    }
}
