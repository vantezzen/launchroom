<?php

declare(strict_types=1);

namespace App\Data\Instance;

use App\Data\Moves\CutoverChecksData;
use App\Data\Servers\ReplaceableServerData;
use App\Data\Servers\ReplacementPlanItemData;
use App\Data\Servers\ServerReplacementData;
use Spatie\LaravelData\Data;

/**
 * The recovery-mode checklist (docs/14 §5.2 step 3).
 */
final class RecoveryChecklistData extends Data
{
    /**
     * @param  list<ReplacementPlanItemData>  $plan
     * @param  list<string>  $openSteps  ids of the steps not done yet, in checklist order (also what finishing records)
     */
    public function __construct(
        public bool $active,
        public ?string $startedAt,
        public ?ReplaceableServerData $previous,
        public ?ReplaceableServerData $current,
        public array $plan,
        public ?ServerReplacementData $replacement,
        public ?string $dashboardHost,
        public CutoverChecksData $checks,
        public array $openSteps,
    ) {}
}
