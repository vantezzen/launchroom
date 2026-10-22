<?php

declare(strict_types=1);

namespace App\Data\Projects;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * "Import a repository" (`POST /projects/import`, flows.md §3): a new project
 * with one service per picked app, the picked databases connected to their
 * apps, and the first deploy started right away.
 */
final class ImportRepositoryData extends Data
{
    /**
     * @param  list<ImportAppData>  $apps
     * @param  list<ImportDatabaseData>  $databases
     */
    public function __construct(
        #[Min(1), Max(100)]
        public string $name,
        public string $repository,
        #[Min(1), Max(255)]
        public string $branch,
        #[Min(1), Max(12)]
        public array $apps,
        #[Max(6)]
        public array $databases = [],
        public bool $deploy = true,
    ) {}
}
