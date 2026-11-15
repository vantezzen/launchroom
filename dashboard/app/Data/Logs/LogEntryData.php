<?php

declare(strict_types=1);

namespace App\Data\Logs;

use App\Enums\LogLineLevel;
use App\Enums\LogLineSource;
use App\Enums\LogStream;
use Spatie\LaravelData\Data;

/**
 * One line in the project log explorer (direction.md §9.8). `id` is stable
 * (chunk id + index), `level` is guessed from the text. Text is raw and
 * rendered as text, never HTML.
 */
final class LogEntryData extends Data
{
    public function __construct(
        public string $id,
        public string $time,
        public LogLineLevel $level,
        public LogLineSource $source,
        public string $service,
        public ?string $serviceId,
        public ?string $replica,
        public ?string $server,
        /** Short SHA (or deployment id when there's no commit). */
        public ?string $deployment,
        public ?string $deploymentId,
        public LogStream $stream,
        public string $message,
        /** Environment name, in project- and team-wide searches. */
        public ?string $environment = null,
        /** Project name, in team-wide searches. */
        public ?string $project = null,
        /** Server public id (the detail drawer links the server). */
        public ?string $serverId = null,
    ) {}
}
