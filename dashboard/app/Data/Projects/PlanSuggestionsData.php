<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Data\Protocol\V1\BuildSummary;
use App\Support\Projects\DatabaseSuggestions;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * What the build plan of one app suggests adding next to it (import flow):
 * worker processes from framework presets and databases from the variables
 * its code references. Database suggestions repeat what the repository
 * inspection found in `.env.example` when both agree; the import flow merges
 * them by engine.
 */
final class PlanSuggestionsData extends Data
{
    /**
     * Frameworks whose config references every driver's variables, so code
     * references say nothing about the database actually used.
     */
    private const array REFERENCES_EVERY_DRIVER = ['laravel', 'symfony', 'rails', 'django'];

    /**
     * @param  list<SuggestedProcessData>  $processes
     * @param  list<SuggestedDatabaseData>  $databases
     */
    public function __construct(
        public array $processes = [],
        public array $databases = [],
    ) {}

    /**
     * From a stored plan summary (`daemon_tasks.result.plan.summary`); empty
     * when absent or malformed.
     */
    public static function fromStored(mixed $summary, string $rootDirectory): self
    {
        if (! is_array($summary) || $summary === []) {
            return new self;
        }

        try {
            return self::fromSummary(BuildSummary::from($summary), $rootDirectory);
        } catch (Throwable) {
            return new self;
        }
    }

    public static function fromSummary(BuildSummary $summary, string $rootDirectory): self
    {
        $processes = [];

        foreach ($summary->processes ?? [] as $process) {
            $processes[] = new SuggestedProcessData($process->name, $process->args);
        }

        $databases = [];

        if (! in_array(strtolower((string) $summary->framework), self::REFERENCES_EVERY_DRIVER, true)) {
            $names = array_filter($summary->envReferences ?? [], is_string(...));

            foreach (DatabaseSuggestions::fromVariables(array_fill_keys($names, ''), 'the code') as $suggestion) {
                $databases[] = new SuggestedDatabaseData($suggestion['engine'], $suggestion['reason'], [trim($rootDirectory, '/')]);
            }
        }

        return new self($processes, $databases);
    }
}
