<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Exports a database's live data or a backup snapshot of it (protocol
 * DbExportSpec, D55).
 */
final class DbExportSpec extends TaskSpec
{
    public function __construct(
        public string $engine,
        public TransferTarget $transfer,
        public ?string $workload = null,
        public ?string $snapshot = null,
        public ?BackupDestination $destination = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::DbExport;
    }
}
