<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Data\Protocol\V1\BuildSummary;
use App\Enums\DatabaseEngine;
use App\Support\Projects\DatabaseSuggestions;
use Spatie\LaravelData\Data;

/**
 * What the build plan of one app suggests adding next to it (import flow):
 * worker processes from framework presets and databases from the variables
 * its code references. Database suggestions repeat what the repository
 * inspection found in `.env.example` when both agree; the import flow merges
 * them by engine. A fallback suggestion (Postgres for Laravel) only joins
 * when the app has no database of that kind yet.
 */
final class PlanSuggestionsData extends Data
{
    /**
     * Frameworks whose config references every driver's variables, so code
     * references say nothing about the database actually used.
     */
    private const array REFERENCES_EVERY_DRIVER = ['laravel', 'symfony', 'rails', 'django'];

    public const string LARAVEL_SQLITE_REASON = 'Laravel uses SQLite by default, and its file would be reset on every deploy';

    /**
     * @param  list<SuggestedProcessData>  $processes
     * @param  list<SuggestedDatabaseData>  $databases
     */
    public function __construct(
        public array $processes = [],
        public array $databases = [],
    ) {}

    public static function fromSummary(BuildSummary $summary, string $rootDirectory): self
    {
        $processes = [];

        foreach ($summary->processes ?? [] as $process) {
            $processes[] = new SuggestedProcessData($process->name, $process->args);
        }

        $databases = [];
        $framework = strtolower((string) $summary->framework);

        // Laravel 11+ defaults to a SQLite file inside the container, which
        // every deploy resets (OD22): a Postgres database is preselected
        // unless the repository already asks for a database server.
        if ($framework === 'laravel') {
            $databases[] = new SuggestedDatabaseData(DatabaseEngine::Postgres, self::LARAVEL_SQLITE_REASON, [trim($rootDirectory, '/')], fallback: true);
        }

        if (! in_array($framework, self::REFERENCES_EVERY_DRIVER, true)) {
            $names = array_filter($summary->envReferences ?? [], is_string(...));

            foreach (DatabaseSuggestions::fromVariables(array_fill_keys($names, ''), 'the code') as $suggestion) {
                $databases[] = new SuggestedDatabaseData($suggestion['engine'], $suggestion['reason'], [trim($rootDirectory, '/')]);
            }
        }

        return new self($processes, $databases);
    }
}
