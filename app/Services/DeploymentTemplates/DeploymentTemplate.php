<?php

namespace App\Services\DeploymentTemplates;

use App\Models\Deployment;
use App\Services\DeploymentManager;
use App\Services\Docker;
use Symfony\Component\Yaml\Yaml;

/**
 * DeploymentTemplate: A deployment template handles deploying a
 * specific type of codebase (e.g. Laravel, Next.js, etc).
 */
abstract class DeploymentTemplate
{
    protected Docker $docker;

    /**
     * Create a new DeploymentTemplate instance.
     *
     * @param  string  $baseDirectory  The base directory where the codebase is stored.
     */
    public function __construct(protected string $baseDirectory, protected Deployment $deployment)
    {
        $this->docker = new Docker($baseDirectory, $deployment);
    }

    /**
     * Check if this deployment template is responsible for the current codebase.
     * This might check that specific files exist, or that it depends on the framework.
     * This is used to automatically determine which deployment template to use.
     */
    abstract public function isResponsible(): bool;

    /**
     * Add the necessary files for the deployment to the codebase.
     * This could include a Dockerfile, updated .env file, etc.
     */
    abstract public function addDeploymentFiles(): void;

    /**
     * Get the services that should be added to the docker-compose.yml file.
     * This should return an array of service names and their configurations.
     *
     * Please note that the main app should live in a container named "app".
     */
    abstract public function getDockerComposeContents(): array;

    /**
     * Update the docker-compose.yml file with the new services.
     */
    protected function updateDockerCompose()
    {
        $compose = $this->getDockerComposeContents();
        $compose = $this->addProxyDataToCompose($compose);
        // TODO: Add external services (databases, etc)
        $compose = $this->addExternalServicesToCompose($compose);

        $dockerCompose = Yaml::dump($compose, 4, 2);
        file_put_contents($this->baseDirectory.'/docker-compose.yml', $dockerCompose);
    }

    protected function addProxyDataToCompose($compose)
    {
        $hostRules = collect($this->deployment->environment->domains)->map(fn ($domain) => "Host(`{$domain}`)")->join(' || ');
        $routerName = $this->deployment->environment->project->slug.'-'.$this->deployment->environment->name;

        $compose['services']['app']['labels'] = [
            'traefik.enable=true',
            'traefik.docker.network=launchroom_net',
            'traefik.http.routers.'.$routerName.'.rule='.$hostRules,
            // 'traefik.http.routers.' . $routerName . '.service=' . $routerName,
            // 'traefik.http.middlewares.gzip.compress=true',
            // 'traefik.http.services.' . $routerName . '.loadbalancer.server.port=3000', // TODO: Make customizable
        ];

        foreach ($compose['services'] as $service => $config) {
            $compose['services'][$service]['networks'] = [
                ...$compose['services'][$service]['networks'] ?? [],
                'launchroom_net',
                'app',
            ];
            $compose['services'][$service]['environment'] = [
                ...$compose['services'][$service]['environment'] ?? [],
                ...$this->getEnvironmentVariables(),
            ];
        }

        $compose['networks']['launchroom_net'] = [
            'external' => true,
        ];
        $compose['networks']['app'] = [];

        return $compose;
    }

    protected function buildAndStartServices()
    {
        // $this->docker->build();
        $this->docker->start();
    }

    /**
     * Get the static environment variables that should be added to the .env file
     * for all applications.
     */
    protected function getStaticEnvironmentVariables()
    {
        return [
            'APP_ENV' => 'production',
        ];
    }

    protected function getEnvironmentVariables()
    {
        $environment = [
            ...$this->getStaticEnvironmentVariables(),
            ...$this->deployment->environment->environment_variables,
        ];
        foreach ($this->deployment->environment->services as $service) {
            $environment = [
                ...$environment,
                ...$service->environment_variables,
            ];
        }

        return $environment;
    }

    protected function addExternalServicesToCompose($compose)
    {
        $externalServices = $this->deployment->environment->services;

        foreach ($externalServices as $serviceDetails) {
            $serviceName = $serviceDetails->service_type;
            if (! isset(DeploymentManager::SERVICES[$serviceName])) {
                $this->deployment->addLogText("Service {$serviceName} not found in services list.");

                continue;
            }

            $serviceClass = DeploymentManager::SERVICES[$serviceName];
            $service = new $serviceClass($serviceDetails);

            $compose = $service->addToDockerCompose($compose);
        }

        return $compose;
    }

    public function deploy()
    {
        $this->addDeploymentFiles();
        $this->updateDockerCompose();
        $this->buildAndStartServices();
    }
}
