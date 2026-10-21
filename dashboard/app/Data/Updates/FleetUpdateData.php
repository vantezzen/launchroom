<?php

declare(strict_types=1);

namespace App\Data\Updates;

use App\Enums\Updates\FleetUpdateStatus;
use App\Models\Updates\FleetUpdate;
use App\Models\Updates\FleetUpdateStep;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A rolling launchroomd update across servers.
 */
final class FleetUpdateData extends Data
{
    /**
     * @param  list<FleetUpdateStepData>  $steps
     */
    public function __construct(
        public string $id,
        public string $toVersion,
        public FleetUpdateStatus $status,
        public ?string $error,
        public array $steps,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $finishedAt,
    ) {}

    public static function fromUpdate(FleetUpdate $update): self
    {
        $steps = $update->steps()->with(['server', 'daemonTask'])->get();

        return new self(
            $update->public_id,
            $update->to_version,
            $update->status,
            $update->error,
            array_values($steps->map(static fn (FleetUpdateStep $step): FleetUpdateStepData => FleetUpdateStepData::fromStep($step))->all()),
            $update->created_at,
            $update->finished_at,
        );
    }
}
