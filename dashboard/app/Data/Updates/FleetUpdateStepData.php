<?php

declare(strict_types=1);

namespace App\Data\Updates;

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
            $step->status === FleetUpdateStepStatus::Updating ? $step->daemonTask?->progress : null,
            $step->error,
        );
    }
}
