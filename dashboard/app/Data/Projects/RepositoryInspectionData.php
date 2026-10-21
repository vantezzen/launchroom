<?php

declare(strict_types=1);

namespace App\Data\Projects;

use Spatie\LaravelData\Data;

/**
 * "We looked at acme/shop" (flows.md §3): the apps of a repository (one, or
 * the workspace apps of a monorepo) and the databases they probably need.
 * `complete` is false when GitHub truncated the file list.
 */
final class RepositoryInspectionData extends Data
{
    /**
     * @param  list<InspectedAppData>  $apps
     * @param  list<SuggestedDatabaseData>  $databases
     */
    public function __construct(
        public string $repository,
        public string $branch,
        public array $apps,
        public array $databases,
        public bool $complete,
    ) {}
}
