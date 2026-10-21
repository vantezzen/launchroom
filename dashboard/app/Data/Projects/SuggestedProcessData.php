<?php

declare(strict_types=1);

namespace App\Data\Projects;

use Spatie\LaravelData\Data;

/**
 * An extra process the build plan suggests for an app (queue worker,
 * scheduler, Reverb, Celery beat; protocol ProcessSuggestion): the import
 * flow offers it as a worker service of that app running `command` (argv)
 * from the same image. `name` is the suggestion's name ("queue").
 */
final class SuggestedProcessData extends Data
{
    /**
     * @param  list<string>  $command
     */
    public function __construct(
        public string $name,
        public array $command,
    ) {}
}
