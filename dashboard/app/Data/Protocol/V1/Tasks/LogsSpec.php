<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Fetches or follows a workload's runtime logs (protocol LogsSpec).
 */
final class LogsSpec extends TaskSpec
{
    public function __construct(
        public string $workload,
        public int $tail,
        public bool $follow,
        public ?string $since = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::Logs;
    }
}
