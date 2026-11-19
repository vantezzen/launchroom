<?php

declare(strict_types=1);

namespace App\Data\Updates;

use App\Enums\DaemonTaskStatus;
use App\Enums\Updates\FleetUpdateStepStatus;
use App\Models\Updates\FleetUpdateStep;
use Spatie\LaravelData\Data;

/**
 * One server's step of a rolling update.
 */
final class FleetUpdateStepData extends Data
{
    public function __construct(
        public int $position,
        public ?string $server,
        public string $serverName,
        public ?string $fromVersion,
        public FleetUpdateStepStatus $status,
        public ?string $progress,
        public ?string $error,
    ) {}

    public static function fromStep(FleetUpdateStep $step): self
    {
        return new self(
            $step->position,
            $step->server?->public_id,
            $step->server_name,
            $step->from_version,
            $step->status,
            $step->status === FleetUpdateStepStatus::Updating ? self::progress($step) : null,
            $step->error,
        );
    }

    /** launchroomd's last line, or that the server hasn't taken the task (docs/11 §4.3). */
    private static function progress(FleetUpdateStep $step): ?string
    {
        $task = $step->daemonTask;

        if ($task?->status === DaemonTaskStatus::Pending && ($task->updated_at?->lt(now()->subSeconds(config()->integer('launchroom.updates.stall_seconds'))) ?? false)) {
            return 'Waiting for the server to start the update. It may be offline.';
        }

        return $task?->progress;
    }
}
