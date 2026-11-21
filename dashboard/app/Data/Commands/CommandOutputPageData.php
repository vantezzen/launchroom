<?php

declare(strict_types=1);

namespace App\Data\Commands;

use Spatie\LaravelData\Data;

/**
 * Output lines of a command after a cursor (the last stored chunk seen).
 */
final class CommandOutputPageData extends Data
{
    /**
     * @param  list<CommandOutputLineData>  $lines
     */
    public function __construct(
        public array $lines,
        public int $cursor,
        public bool $hasMore,
    ) {}
}
