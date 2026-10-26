<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * `POST …/services/{service}/stop|start|restart` (D68): the service after
 * the action and the deployments it started (Start and Restart roll out a
 * deployment per service; Stop starts none).
 */
final class ServiceActionResultData extends Data
{
    /**
     * @param  list<DeploymentResource>  $deployments
     */
    public function __construct(
        public ServiceResource $service,
        public array $deployments,
    ) {}
}
