<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * A project with its environments (previews included).
 */
final class ProjectResource extends Data
{
    /**
     * @param  list<EnvironmentRefData>  $environments
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $createdAt,
        public array $environments,
        public string $dashboardUrl,
    ) {}
}
