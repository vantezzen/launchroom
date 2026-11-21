<?php

declare(strict_types=1);

namespace App\Data\Api\Events;

use App\Data\Commands\CommandOutputLineData;
use Spatie\LaravelData\Data;

/**
 * `lines` of a command's output stream (D105); the event id is `cursor`.
 */
final class CommandLinesEvent extends Data
{
    /**
     * @param  list<CommandOutputLineData>  $lines
     */
    public function __construct(
        public array $lines,
        public int $cursor,
    ) {}
}
