<?php

namespace App\Services\DeploymentServices;

use App\Models\Environment;
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
     * Get the names of critical services that should be started before the main app.
     * This is used to ensure that the main app can connect to these services.
     */
    public function getCriticalServiceNames(): array
    {
        return [];
    }

    /**
     * Update the docker-compose.yml file with the new service.
     */
    public function addToDockerCompose(array $compose, array $baseServices)
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

        $criticalServices = $this->getCriticalServiceNames();
        foreach ($baseServices as $baseService) {
            $compose['services'][$baseService]['depends_on'] ??= [];
            $compose['services'][$baseService]['depends_on'] = array_merge(
                $compose['services'][$baseService]['depends_on'],
                $criticalServices
            );
        }

        return $compose;
    }

    abstract public static function createServiceInEnvironment(Environment $environment, array $settings): Service;
}
