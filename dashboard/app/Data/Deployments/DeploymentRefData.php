<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Spatie\LaravelData\Data;

/**
 * A short reference to another deployment of the same service (the live
 * one, the previous one) for the deployment page.
 */
final class DeploymentRefData extends Data
{
    public function __construct(
        public string $id,
        public ?string $commitSha,
        public ?string $commitMessage,
        public DeploymentStatus $status,
        public string $queuedAt,
        /** When it finished (went live, failed or was cancelled). */
        public ?string $finishedAt = null,
    ) {}

    public static function of(Deployment $deployment): self
    {
        return new self($deployment->public_id, $deployment->commit_sha, $deployment->commit_message, $deployment->status, $deployment->queued_at->toIso8601String(), $deployment->finished_at?->toIso8601String());
    }
}
