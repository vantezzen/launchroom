<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Frees disk space on a server now, with launchroomd's disk-pressure
 * settings (protocol CleanupSpec, "Free space now"). No parameters; the
 * result is TaskCompletion.cleanup. Pinned: its creator checks the server's
 * `cleanupTask` feature.
 */
final class CleanupSpec extends TaskSpec
{
    public function type(): DaemonTaskType
    {
        return DaemonTaskType::Cleanup;
    }
}
