<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * An edge of the preview: a connection, or a worker's process link to its app.
 */
final class BlueprintEdgeData extends Data
{
    public function __construct(
        public string $from,
        public string $to,
        #[LiteralTypeScriptType("'connection' | 'process'")]
        public string $type,
    ) {}
}
