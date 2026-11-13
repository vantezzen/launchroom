<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use Spatie\LaravelData\Data;

/**
 * A canvas edge: a connection (injects variables), the process link of a
 * worker/cron to its parent app (not removable), or an inherited connection:
 * a worker/cron gets what its app's connection injects (`via` = the app).
 */
final class CanvasEdgeData extends Data
{
    /**
     * @param  list<string>  $variables  injected variable names
     */
    public function __construct(
        public string $id,
        /** `connection`, `process` or `inherited`. */
        public string $type,
        public string $source,
        public string $target,
        public array $variables = [],
        public ?ConnectionMode $mode = null,
        public ?ConnectionStyle $style = null,
        public bool $staged = false,
        /** Inherited edges: the app whose connection this is. */
        public ?string $via = null,
    ) {}
}
