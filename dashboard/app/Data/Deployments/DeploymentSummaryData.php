<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A deployment row in lists and cards (docs/08 §3.4, §3.5). Never carries
 * the env snapshot; `failureSummary` is the first line of the failure message.
 */
final class DeploymentSummaryData extends Data
{
    /**
     * @param  list<string>  $urls  https URLs of the service's domains in this environment
     */
    public function __construct(
        public string $id,
        public DeploymentStatus $status,
        public string $statusLabel,
        public DeploymentTrigger $trigger,
        public string $projectId,
        public string $serviceId,
        public string $serviceName,
        public string $serviceSlug,
        public string $environmentId,
        public string $environmentName,
        public ?string $gitRef,
        public ?string $commitSha,
        public ?string $commitMessage,
        public ?string $commitAuthor,
        public ?string $actorName,
        public ?DeploymentFailureClass $failureClass,
        public ?string $failureSummary,
        public bool $isActive,
        public array $urls,
        public CarbonInterface $queuedAt,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt,
        public ?int $durationSeconds,
        public ?string $rollbackOfId,
        public bool $canRollback,
        /** The commit author's avatar (GitHub's avatar host, a git connection's origin or Gravatar, docs/19 §7); null → initials. */
        public ?string $commitAuthorAvatarUrl = null,
        /** The live deployment of a stopped service (D68): it runs nothing until Start. */
        public ?ServiceStoppedData $stopped = null,
    ) {}
}
