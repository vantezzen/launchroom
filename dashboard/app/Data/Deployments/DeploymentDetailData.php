<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Data\ConfigAsCode\DeploymentConfigErrorsData;
use Spatie\LaravelData\Data;

/**
 * The deployment detail page (docs/08 §3.5).
 */
final class DeploymentDetailData extends Data
{
    /**
     * @param  list<DeploymentStepData>  $steps
     * @param  list<ResolvedConfigEntryData>  $config
     * @param  list<EnvSnapshotEntryData>  $env
     * @param  list<BuildHistoryEntryData>  $buildHistory  the service instance's last 30 finished deployments, oldest first
     */
    public function __construct(
        public DeploymentSummaryData $deployment,
        public string $projectName,
        public ?int $revision,
        public array $steps,
        public ?DeploymentFailureData $failure,
        public array $config,
        public array $env,
        public bool $canRedeploy,
        public bool $canCancel,
        /** A staged deployment that is ready: route traffic to it (docs/06 §6.5). */
        public bool $canPromote = false,
        /** A known failure explained, with an optional fix action (null: unknown or not failed). */
        public ?FailureExplanationData $failureExplanation = null,
        public array $buildHistory = [],
        /** The service's logo key (canvas glyph vocabulary). */
        public string $logo = 'app',
        /** The server that built the image (null: no build ran here). */
        public ?string $builtOn = null,
        /** The live deployment of the service when it isn't this one ("still serving 3b7d5e1"). */
        public ?DeploymentRefData $serving = null,
        /** The previous deployment of the service, for "Compare". */
        public ?DeploymentRefData $previous = null,
        /** A link to the commit range on GitHub (previous → this commit), when the source is a GitHub repository. */
        public ?string $compareUrl = null,
        /** The launchroom.json problems a push failed on (docs/18 §6). */
        public ?DeploymentConfigErrorsData $configErrors = null,
    ) {}
}
