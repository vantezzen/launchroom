<?php

declare(strict_types=1);

namespace App\Data\Api\Events;

use App\Data\Logs\LogEntryData;
use Spatie\LaravelData\Data;

/**
 * `lines` of an environment log stream; the event id is `cursor`.
 */
final class EnvironmentLinesEvent extends Data
{
    /**
     * @param  list<LogEntryData>  $entries
     */
    public function __construct(
        public array $entries,
        public int $cursor,
    ) {}
}
