<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * Why a commit wasn't deployed (DeployCommit).
 */
final class CommitNotDeployed extends Data
{
    public function __construct(
        public string $message,
    ) {}
}
