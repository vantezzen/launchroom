<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Data;

/**
 * A one-off command the team named in launchroom.json (`deploy.commands`,
 * D105, docs/18 §2.3): listed in the Commands tab next to the framework's,
 * run with `launchroom exec <name>`. An argv, never a shell string (docs/09
 * R1). Rules: {@see DeploySettings::rules()}.
 */
final class NamedCommand extends Data
{
    /**
     * @param  list<string>  $command
     */
    public function __construct(
        public string $name,
        public array $command,
        public ?string $description = null,
        /** Changes data or state: the dashboard asks before it runs. */
        public bool $danger = false,
    ) {}

    public const int MAX_PER_SERVICE = 32;

    public const int MAX_DESCRIPTION = 200;
}
