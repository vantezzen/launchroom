<?php

namespace App\Services\DeploymentServices;

use App\Models\Service;

abstract class DeploymentService
{
    public function __construct(
        protected Service $service
    ) {}

    /**
     * Get the contents for the docker-compose.yml file.
     * This might include the service itself, volumes, etc.
     */
    abstract public function getDockerComposeContents(): array;

    /**
     * Update the docker-compose.yml file with the new service.
     */
    public function addToDockerCompose(array $compose)
    {
        $ownContent = $this->getDockerComposeContents();
        if (isset($ownContent['services'])) {
            $compose['services'] ??= [];
            $compose['services'] = array_merge($compose['services'], $ownContent['services']);
        }
        if (isset($ownContent['volumes'])) {
            $compose['volumes'] ??= [];
            $compose['volumes'] = array_merge($compose['volumes'], $ownContent['volumes']);
        }

        return $compose;
    }
}
