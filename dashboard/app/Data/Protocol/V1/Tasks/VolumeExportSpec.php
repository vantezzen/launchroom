<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Exports a volume's files or a backup snapshot of them (protocol
 * VolumeExportSpec, D55).
 */
final class VolumeExportSpec extends TaskSpec
{
    /**
     * @param  'live'|'pause'|null  $consistency
     */
    public function __construct(
        public TransferTarget $transfer,
        public ?string $volume = null,
        public ?string $consistency = null,
        public ?string $snapshot = null,
        public ?BackupDestination $destination = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::VolumeExport;
    }
}
