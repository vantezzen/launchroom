<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Models\DeploymentStep;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One recorded step of a deployment's timeline (docs/04 §6). `message` is
 * the step's user-facing outcome; step meta beyond that is never exposed.
 */
final class DeploymentStepData extends Data
{
    public function __construct(
        public DeploymentStepName $name,
        public DeploymentStepStatus $status,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt,
        public ?string $message,
    ) {}

    /** `fallback`: the message while the step has none of its own (pull progress). */
    public static function fromStep(DeploymentStep $step, ?string $fallback = null): self
    {
        $message = $step->meta['message'] ?? null;

        return new self(
            name: $step->name,
            status: $step->status,
            startedAt: $step->started_at,
            finishedAt: $step->finished_at,
            message: is_string($message) ? mb_substr($message, 0, 2000) : $fallback,
        );
    }
}
