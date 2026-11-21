<?php

declare(strict_types=1);

namespace App\Data\Commands;

use Spatie\LaravelData\Data;

/**
 * One line of a command's output (D105). `number` is stable for the run;
 * `stream` is stdout, stderr or system (launchroom's own lines: pulling
 * the image, the exit code). Stored masked (docs/09 R16); raw text, never
 * HTML.
 */
final class CommandOutputLineData extends Data
{
    public function __construct(
        public int $number,
        public string $time,
        public string $stream,
        public string $text,
    ) {}
}
