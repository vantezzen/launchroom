<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use Spatie\LaravelData\Data;

/**
 * A canvas edge: a connection (injects variables) or the process link of a
 * worker/cron to its parent app (not removable).
 */
final class CanvasEdgeData extends Data
{
    /**
     * @param  list<string>  $variables  injected variable names
     */
    public function __construct(
        public string $id,
        /** `connection` or `process`. */
        public string $type,
        public string $source,
        public string $target,
        public array $variables = [],
        public ?ConnectionMode $mode = null,
        public ?ConnectionStyle $style = null,
        public bool $staged = false,
    ) {}
}
