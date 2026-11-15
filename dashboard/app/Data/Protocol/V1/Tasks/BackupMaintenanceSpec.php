<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * forget, prune or check on a repository (protocol BackupMaintenanceSpec).
 */
final class BackupMaintenanceSpec extends TaskSpec
{
    /**
     * @param  'forget'|'prune'|'check'  $operation
     * @param  list<string>|null  $tags
     */
    public function __construct(
        public string $operation,
        public BackupDestination $destination,
        public ?Retention $retention = null,
        public ?array $tags = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::BackupMaintenance;
    }
}
