<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStepName;
use Spatie\LaravelData\Data;

/**
 * A failed deployment explained like its page in the dashboard (docs/04 §5,
 * `getDeploymentFailure`): the outcome, the catalogue's explanation when
 * the failure is a known one, the build's hint codes, the failing step with
 * its last log lines (masked when they were stored, ANSI removed) and the
 * fixes, each with how to apply it.
 */
final class ApiFailureReportData extends Data
{
    /**
     * @param  list<ApiFailureHintData>  $hints
     * @param  list<ApiLogExcerptLineData>  $excerpt
     * @param  list<ApiFailureFixData>  $fixes
     */
    public function __construct(
        public string $deploymentId,
        public DeploymentFailureClass $class,
        /** The outcome in a few words ("Build failed", "Health check failed"). */
        public string $title,
        /** The raw failure message. */
        public ?string $message,
        /** What to do next, for any failure of this class (used when there is no explanation). */
        public string $hint,
        public ?ApiFailureExplanationData $explanation,
        public array $hints,
        /** The step that failed (null when no step was recorded as failed). */
        public ?DeploymentStepName $failingStep,
        /** The failing step's last lines, in order; numbers match the deployment's log. */
        public array $excerpt,
        /** The first line of the failing step that reads like an error (also in the excerpt). */
        public ?int $errorLine,
        public array $fixes,
        /** The commit still serving while this deployment failed, if any. */
        public ?string $servingCommit,
        public string $dashboardUrl,
    ) {}
}
