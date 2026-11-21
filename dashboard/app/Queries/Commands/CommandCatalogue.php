<?php

declare(strict_types=1);

namespace App\Queries\Commands;

use App\Data\Canvas\DetectedPlanData;
use App\Data\Commands\CommandCatalogueData;
use App\Data\Commands\CommandData;
use App\Data\Deployments\DeploymentPlanData;
use App\Data\Protocol\V1\CommandSuggestion;
use App\Data\Services\NamedCommand;
use App\Enums\Commands\CommandSource;
use App\Models\Deployment;
use App\Models\ServiceInstance;

/**
 * What a service's Commands tab lists (D105, docs/08 §22): the framework
 * commands of the live deployment's build (`BuildSummary.commands`, from
 * launchroomd's presets), then the commands launchroom.json names in the
 * environment's applied configuration (`deploy.commands`). A named command
 * replaces a framework command of the same name.
 */
final class CommandCatalogue
{
    /**
     * Frameworks whose presets list commands: a live build of one without
     * the list was made before launchroomd had them.
     */
    private const array FRAMEWORKS_WITH_COMMANDS = ['laravel', 'symfony', 'rails', 'django', 'phoenix'];

    public function for(ServiceInstance $instance): CommandCatalogueData
    {
        $instance->loadMissing(['service', 'environment.lastAppliedRevision', 'activeDeployment']);
        $live = $instance->activeDeployment;
        $named = $this->named($instance);
        $taken = array_flip(array_map(static fn (NamedCommand $command): string => $command->name, $named));

        $framework = [];

        foreach ($this->suggestions($live) as $suggestion) {
            if (! isset($taken[$suggestion->name])) {
                $framework[] = new CommandData($suggestion->name, $suggestion->label, $suggestion->description, $suggestion->args, $suggestion->danger, CommandSource::Framework);
            }
        }

        $config = array_map(static fn (NamedCommand $command): CommandData => new CommandData(
            $command->name,
            $command->name,
            $command->description,
            $command->command,
            $command->danger,
            CommandSource::Config,
        ), $named);

        // A deployment whose build failed or never ran has no plan.
        $plan = $live instanceof Deployment ? $live->getAttribute('plan') : null;
        $plan = $plan instanceof DeploymentPlanData ? $plan : null;
        $id = $plan?->framework();

        return new CommandCatalogueData(
            framework: $id === null ? null : DetectedPlanData::frameworkLabel($id),
            commands: [...$framework, ...$config],
            awaitingBuild: $id !== null && in_array(strtolower($id), self::FRAMEWORKS_WITH_COMMANDS, true) && $plan->summary?->commands === null,
        );
    }

    /**
     * @return list<CommandSuggestion>
     */
    private function suggestions(?Deployment $live): array
    {
        $plan = $live instanceof Deployment ? $live->getAttribute('plan') : null;

        return $plan instanceof DeploymentPlanData ? $plan->summary->commands ?? [] : [];
    }

    /**
     * @return list<NamedCommand>
     */
    private function named(ServiceInstance $instance): array
    {
        $spec = $instance->environment->lastAppliedRevision?->spec->service($instance->service->public_id);

        return $spec->deploy->commands ?? [];
    }
}
