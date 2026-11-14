<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * Service panel → Deployments (docs/08 §3.4): the active deployment and the
 * history of one service instance. Both the JSON endpoint and the canvas
 * page (a deep link opens with it) send this, so the two can't drift.
 */
final class ServiceDeploymentsData extends Data
{
    /**
     * @param  list<DeploymentSummaryData>  $deployments  newest first
     */
    public function __construct(
        public ?DeploymentSummaryData $active,
        public array $deployments,
        /** The list's expanded first row: in progress, else live, else the newest. */
        public ?ServiceDeploymentCurrentData $current,
        /** A stopped service starts from its header (D68), not with Redeploy. */
        public bool $canDeploy,
    ) {}
}
