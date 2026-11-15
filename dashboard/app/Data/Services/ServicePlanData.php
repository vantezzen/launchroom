<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Data\Protocol\V1\BuildSummary;
use App\Enums\ServicePlanStatus;
use Spatie\LaravelData\Data;

/**
 * `service_instances.plan`: the latest plan preview of a service instance
 * ("we detected…", docs/04 §4), written by PlanService and RecordPlanResult.
 * A failed plan has `error`, and `summary` when the daemon got far enough to
 * detect something; a running one keeps the previous summary.
 */
final class ServicePlanData extends Data
{
    public function __construct(
        public ServicePlanStatus $status,
        /** The plan task (public id) while it runs. */
        public ?string $task = null,
        /** Hash of the commit and build inputs the task plans. */
        public ?string $inputs = null,
        public ?string $sha = null,
        public ?string $branch = null,
        public ?string $requestedAt = null,
        public ?string $finishedAt = null,
        public ?BuildSummary $summary = null,
        public ?string $error = null,
        /** App\Enums\TaskFailureClass, or a newer launchroomd's class. */
        public ?string $failureClass = null,
    ) {}
}
