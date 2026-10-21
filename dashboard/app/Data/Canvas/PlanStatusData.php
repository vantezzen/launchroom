<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Services\ServicePlanData;
use Spatie\LaravelData\Data;

/**
 * State of a service instance's plan preview for the Build section
 * (docs/04 §4): `planning` while a plan task runs (the UI waits live),
 * `ready`, or `failed` with the reason. The detected values themselves are
 * in ServiceSettingsData::$detected.
 */
final class PlanStatusData extends Data
{
    public function __construct(
        /** `planning`, `ready` or `failed`. */
        public string $status,
        public ?string $commitSha,
        public ?string $branch,
        public ?string $finishedAt,
        public ?string $error,
    ) {}

    public static function fromPlan(ServicePlanData $plan): self
    {
        return new self($plan->status, $plan->commitSha, $plan->branch, $plan->finishedAt, $plan->error);
    }
}
