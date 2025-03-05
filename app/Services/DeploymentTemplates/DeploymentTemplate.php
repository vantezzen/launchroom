<?php
namespace App\Services\DeploymentTemplates;

use App\Models\Deployment;
use App\Services\Docker;
use Symfony\Component\Yaml\Yaml;

/**
 * DeploymentTemplate: A deployment template handles deploying a
 * specific type of codebase (e.g. Laravel, Next.js, etc).
 */
abstract class DeploymentTemplate {
  protected Docker $docker;

  /**
   * Create a new DeploymentTemplate instance.
   *
   * @param string $baseDirectory The base directory where the codebase is stored.
   */
  public function __construct(protected string $baseDirectory, protected Deployment $deployment) {
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
  protected function updateDockerCompose() {
    $compose = $this->getDockerComposeContents();
    $compose = $this->addProxyDataToCompose($compose);

    // TODO: Add external services (databases, etc)

    $dockerCompose = Yaml::dump($compose, 4, 2);
    file_put_contents($this->baseDirectory . '/docker-compose.yml', $dockerCompose);
  }

  protected function addProxyDataToCompose($compose) {
    $hostRules = collect($this->deployment->environment->domains)->map(fn($domain) => "Host(`{$domain}`)")->join(' || ');
    $routerName = $this->deployment->environment->project->slug . '-' . $this->deployment->environment->name;

    $compose['services']['app']['labels'] = [
      'traefik.enable=true',
      'traefik.http.routers.' . $routerName . '.rule=' . $hostRules,
      'traefik.http.routers.' . $routerName . '.service=' . $routerName,
      'traefik.http.middlewares.gzip.compress=true',
      // 'traefik.http.services.' . $this->deployment->name . '.loadbalancer.server.port=' . $this->deployment->port,
    ];

    return $compose;
  }

  protected function buildAndStartServices() {
    // $this->docker->build();
    $this->docker->start();
  }

  public function deploy() {
    $this->addDeploymentFiles();
    $this->updateDockerCompose();
    $this->buildAndStartServices();
  }
}