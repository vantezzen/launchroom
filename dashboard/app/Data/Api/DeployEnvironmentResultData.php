<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * `POST /environments/{environment}/deploy`: the new revision and the
 * deployments it started (none when no service needed one).
 */
final class DeployEnvironmentResultData extends Data
{
    /**
     * @param  list<DeploymentResource>  $deployments
     */
    public function __construct(
        public int $revision,
        public array $deployments,
    ) {}
}
