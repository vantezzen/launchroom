<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Inspects a volume or extracts an archive into it (protocol
 * VolumeImportSpec, D55).
 */
final class VolumeImportSpec extends TaskSpec
{
    /**
     * @param  'inspect'|'import'  $mode
     */
    public function __construct(
        public string $mode,
        public string $volume,
        public ?TransferRef $file = null,
        public ?bool $replace = null,
        public ?int $stripComponents = null,
        public ?FileOwner $owner = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::VolumeImport;
    }
}
