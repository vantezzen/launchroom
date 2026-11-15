<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Projects\PlanSuggestionsData;
use App\Enums\PlanPreviewStatus;
use Spatie\LaravelData\Data;

/**
 * Answer to "what will launchroom build from this repository?" while adding
 * a service.
 */
final class PlanPreviewData extends Data
{
    public function __construct(
        public PlanPreviewStatus $status,
        public ?DetectedPlanData $plan = null,
        public ?string $message = null,
        /** Worker processes and databases the plan suggests (import flow); null until ready. */
        public ?PlanSuggestionsData $suggestions = null,
    ) {}

    public static function unavailable(string $message): self
    {
        return new self(PlanPreviewStatus::Unavailable, null, $message);
    }
}
