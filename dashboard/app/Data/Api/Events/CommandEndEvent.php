<?php

declare(strict_types=1);

namespace App\Data\Api\Events;

use App\Enums\Commands\CommandRunStatus;
use Spatie\LaravelData\Data;

/**
 * `end` of a command's output stream: the run ended (the stream closes).
 * `exitCode` is the container's, when it exited.
 */
final class CommandEndEvent extends Data
{
    public function __construct(
        public CommandRunStatus $status,
        public ?int $exitCode,
        public ?string $error,
    ) {}
}
