<?php

declare(strict_types=1);

namespace App\Data\Commands;

use Spatie\LaravelData\Data;

/**
 * The Commands tab of a service (D105, docs/08 §22).
 */
final class CommandsTabData extends Data
{
    /**
     * @param  list<CommandRunData>  $runs  newest first
     * @param  list<int>  $timeouts  the "Stops after" choices, in seconds
     */
    public function __construct(
        public CommandCatalogueData $catalogue,
        public array $runs,
        /** Cursor of the next page of runs; null when this is all. */
        public ?string $moreRuns,
        /** Why nothing can run right now (Run is disabled); null when it can. */
        public ?string $blocked,
        public string $environment,
        /** Production: the danger confirm names it. */
        public bool $production,
        public int $defaultTimeout,
        public array $timeouts,
    ) {}
}
