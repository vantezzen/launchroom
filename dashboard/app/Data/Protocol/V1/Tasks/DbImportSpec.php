<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\V1\Resources;
use App\Enums\DaemonTaskType;

/**
 * Inspects a database or imports into it (protocol DbImportSpec, D55).
 */
final class DbImportSpec extends TaskSpec
{
    /**
     * @param  'inspect'|'import'  $mode
     */
    public function __construct(
        public string $mode,
        public string $engine,
        public string $workload,
        public Resources $resources,
        public ?DbImportSource $source = null,
        public ?bool $replace = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::DbImport;
    }
}
