<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * The new dashboard's checklist after a control-plane move (docs/14 §13.4).
 */
final class MoveChecklistData extends Data
{
    /**
     * @param  list<string>  $openSteps  ids of the steps not done yet, in checklist order (also what finishing records)
     */
    public function __construct(
        public ?string $startedAt,
        public ?string $previousUrl,
        public string $url,
        public ?CutoverMachineData $previous,
        public ?CutoverMachineData $current,
        public CutoverChecksData $checks,
        public bool $needsRecoveryKit,
        public array $openSteps,
    ) {}
}
