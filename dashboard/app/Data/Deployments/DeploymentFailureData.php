<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\DeploymentFailureClass;
use Spatie\LaravelData\Data;

/**
 * Why a deployment failed, with what to do next (docs/04 §6, design-system §8).
 */
final class DeploymentFailureData extends Data
{
    public function __construct(
        public DeploymentFailureClass $class,
        public string $title,
        public ?string $message,
        public string $hint,
    ) {}

    public static function for(DeploymentFailureClass $class, ?string $message): self
    {
        [$title, $hint] = match ($class) {
            DeploymentFailureClass::PlanError => ['Could not plan the build', 'launchroom could not work out how to build this app. Set install, build and start commands in the service settings, or add a Dockerfile.'],
            DeploymentFailureClass::BuildError => ['Build failed', 'A build step exited with an error. The first error line is highlighted in the build log.'],
            DeploymentFailureClass::Transient => ['Registry or network trouble', 'This is usually temporary. Redeploy to try again.'],
            DeploymentFailureClass::ReleaseError => ['Release command failed', 'The release command exited with an error, so the new version was not rolled out. Check the command in the service settings.'],
            DeploymentFailureClass::RuntimeError => ['The app exited', 'The container stopped while starting. The last runtime log lines usually say why.'],
            DeploymentFailureClass::PortMismatch => ['The app listens on another port', 'Update the port in the service settings to the one the app listens on.'],
            DeploymentFailureClass::HealthTimeout => ['Health check timed out', 'The app did not answer its health check in time. Check the health check path and start period in the service settings.'],
            DeploymentFailureClass::ConfigError => ['Configuration error', 'Fix the configuration named in the message and deploy again.'],
            DeploymentFailureClass::PlacementError => ['No server can run this service', 'Check server roles and capacity, or bring the server that holds the volume back online.'],
        };

        return new self($class, $title, $message, $hint);
    }
}
