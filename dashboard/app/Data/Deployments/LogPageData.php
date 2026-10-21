<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * A page of deployment log lines after a cursor (the last log chunk seen).
 */
final class LogPageData extends Data
{
    /**
     * @param  list<LogLineData>  $lines
     */
    public function __construct(
        public array $lines,
        public int $cursor,
        public bool $hasMore,
    ) {}
}
