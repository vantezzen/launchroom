<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * How the service did since this deployment went live, against the hour
 * before (docs/08 §12 "Since this deploy"): requests per second, error
 * rate and p95 for both windows, a per-bucket series around the deploy
 * (`liveAt` marks it) and, when errors rose past the team's error-rate
 * threshold, the deployment to roll back to.
 */
final class DeploymentImpactData extends Data
{
    /**
     * @param  list<string>  $timestamps  bucket starts, UTC
     * @param  list<float|null>  $requestsPerSecond  aligned with `timestamps`
     * @param  list<float|null>  $errorRatePct  aligned with `timestamps`; null without requests
     */
    public function __construct(
        public string $liveAt,
        /** Minutes the "after" window covers (up to 60). */
        public int $minutesAfter,
        /** Whether a newer deployment replaced it before the hour was over. */
        public bool $replaced,
        public ?DeploymentImpactWindowData $before,
        public DeploymentImpactWindowData $after,
        public int $bucketSeconds,
        public array $timestamps,
        public array $requestsPerSecond,
        public array $errorRatePct,
        /** The team's error-rate alert threshold in percent. */
        public float $thresholdPct,
        /** Errors rose past the threshold after this deploy. */
        public bool $worse,
        /** The previous deployment, offered as a rollback when `worse` and allowed. */
        public ?DeploymentSummaryData $rollbackTo = null,
    ) {}
}
