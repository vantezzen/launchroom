<?php

declare(strict_types=1);

namespace App\Data\Logs;

use Spatie\LaravelData\Data;

/**
 * A log search (environment, project or team, App\Queries\LogSearch): the
 * newest matching lines of the range (oldest first, at most LIMIT), the range
 * bounds, the histogram buckets and deploy markers, the number of matching
 * lines and the cursor for live polling (`?after=` returns only newer chunks,
 * without buckets). `truncated` means older matching lines were left out.
 */
final class LogExplorerPageData extends Data
{
    /**
     * @param  list<LogEntryData>  $entries
     * @param  list<LogMarkerData>  $markers
     * @param  list<LogBucketData>  $buckets
     */
    public function __construct(
        public array $entries,
        public array $markers,
        public string $from,
        public string $to,
        public int $cursor,
        public bool $truncated,
        /** Services currently followed by a live tail started by this user. */
        public int $tailing,
        public array $buckets = [],
        /** Lines in the range that match the whole query. */
        public int $matches = 0,
    ) {}
}
