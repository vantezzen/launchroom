<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use Spatie\LaravelData\Data;

/**
 * An app service built from the import's repository: the root directory
 * (Compose `build.context`, '' = the repository root) and, when given, its
 * Dockerfile relative to that directory.
 */
final class BlueprintBuild extends Data
{
    public function __construct(
        public string $context = '',
        public ?string $dockerfile = null,
    ) {}
}
