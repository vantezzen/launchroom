<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The service panel header's actions (D68, docs/08 §13): Deploy, Restart,
 * Stop and Start, and what the dialogs need to say.
 */
final class ServiceControlsData extends Data
{
    public function __construct(
        /** The environment's `deploy` permission (every action needs it). */
        public bool $canControl,
        /** It has a live deployment (Restart and Stop make sense). */
        public bool $running,
        public bool $stopped,
        public ?CarbonInterface $stoppedAt,
        public ?string $stoppedBy,
        /** Apps and image services deploy a new version; databases, workers and crons restart. */
        public bool $versioned,
        /** It has staged changes: Deploy is the environment's "Review & apply". */
        public bool $staged,
        /** A deployment of it hasn't finished (Restart waits for it). */
        public bool $deploying,
        /** Its workers and crons (apps): they stop, start and restart with it when chosen. */
        public int $processes,
        /** Services connected to it (databases): they lose it while it is stopped. */
        public int $dependents,
    ) {}
}
