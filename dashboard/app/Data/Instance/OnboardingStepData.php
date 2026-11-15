<?php

declare(strict_types=1);

namespace App\Data\Instance;

use App\Enums\Instance\OnboardingStep;
use App\Enums\Instance\OnboardingStepStatus;
use Spatie\LaravelData\Data;

/**
 * One item of the first-run checklist (docs/08 §3.1).
 */
final class OnboardingStepData extends Data
{
    public function __construct(
        public OnboardingStep $id,
        public OnboardingStepStatus $status,
        public bool $skippable,
    ) {}
}
