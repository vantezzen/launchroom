<?php

declare(strict_types=1);

namespace App\Data\Commands;

use App\Enums\Commands\CommandSource;
use Spatie\LaravelData\Data;

/**
 * One command of a service's catalogue (D105): a framework command from the
 * live build, or one the team named in launchroom.json. `name` is what
 * `launchroom exec <name>` and `POST …/command-runs {"name"}` take.
 */
final class CommandData extends Data
{
    /**
     * @param  list<string>  $argv
     */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $description,
        public array $argv,
        /** It changes data: the dashboard asks before it runs. */
        public bool $danger,
        public CommandSource $source,
    ) {}
}
