<?php

declare(strict_types=1);

namespace App\Data\Commands;

use Spatie\LaravelData\Data;

/**
 * The commands a service lists (D105): its framework's, from the live
 * deployment's build, then the ones launchroom.json names.
 */
final class CommandCatalogueData extends Data
{
    /**
     * @param  list<CommandData>  $commands
     */
    public function __construct(
        /** The framework's display name ("Laravel"); null without one. */
        public ?string $framework,
        public array $commands,
        /** The live build predates framework commands: they show up after the next build. */
        public bool $awaitingBuild = false,
    ) {}

    public function find(string $name): ?CommandData
    {
        foreach ($this->commands as $command) {
            if ($command->name === $name) {
                return $command;
            }
        }

        return null;
    }
}
