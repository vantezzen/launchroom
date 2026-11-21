<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * One line of a failure's excerpt: `number` is the line's number in the
 * deployment's log (`#L42` on its page), `error` whether it reads like an
 * error. Text is plain (no ANSI escapes).
 */
final class ApiLogExcerptLineData extends Data
{
    public function __construct(
        public int $number,
        public string $stream,
        public string $text,
        public bool $error,
    ) {}
}
