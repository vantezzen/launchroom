<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Models\Deployment;
use App\Models\EnvironmentRevision;

/**
 * Result of a Deploy: the new revision and the deployments it started.
 */
final readonly class DeployResult
{
    /**
     * @param  list<Deployment>  $deployments
     */
    public function __construct(
        public EnvironmentRevision $revision,
        public array $deployments,
    ) {}
}
