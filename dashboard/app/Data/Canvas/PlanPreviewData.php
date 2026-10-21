<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Projects\PlanSuggestionsData;
use Spatie\LaravelData\Data;

/**
 * Answer to "what will launchroom build from this repository?" while adding
 * a service. `pending` means a plan task is running (poll again);
 * `unavailable` means detection happens with the first build instead.
 */
final class PlanPreviewData extends Data
{
    public function __construct(
        /** `ready`, `pending`, `failed` or `unavailable`. */
        public string $status,
        public ?DetectedPlanData $plan = null,
        public ?string $message = null,
        /** Worker processes and databases the plan suggests (import flow); null until ready. */
        public ?PlanSuggestionsData $suggestions = null,
    ) {}

    public static function unavailable(string $message): self
    {
        return new self('unavailable', null, $message);
    }
}
