<?php

declare(strict_types=1);

namespace App\Data\Api\Events;

use App\Enums\Commands\CommandRunStatus;
use Spatie\LaravelData\Data;

/**
 * `status` of a command's output stream: sent when the status changes.
 */
final class CommandStatusEvent extends Data
{
    public function __construct(
        public CommandRunStatus $status,
        public bool $finished,
    ) {}
}
