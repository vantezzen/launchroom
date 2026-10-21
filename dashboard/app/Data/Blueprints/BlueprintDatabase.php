<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use App\Enums\DatabaseEngine;
use Spatie\LaravelData\Data;

/**
 * A managed database: the engine driver owns its image, volume and
 * per-environment credentials (docs/04 §10).
 */
final class BlueprintDatabase extends Data
{
    public function __construct(
        public DatabaseEngine $engine,
        public string $version,
    ) {}
}
