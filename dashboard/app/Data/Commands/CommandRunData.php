<?php

declare(strict_types=1);

namespace App\Data\Commands;

use App\Enums\Commands\CommandRunStatus;
use Spatie\LaravelData\Data;

/**
 * One one-off command (D105), for the Commands tab, the API, the CLI and
 * MCP. `exitCode` is the container's exit code once it exited; `error`
 * says why it didn't run to its end. Never an env value.
 */
final class CommandRunData extends Data
{
    /**
     * @param  list<string>  $argv
     */
    public function __construct(
        public string $id,
        public CommandRunStatus $status,
        /** True once the run ended (succeeded, failed, timed out, cancelled or couldn't run). */
        public bool $finished,
        public array $argv,
        /** The listed command it came from (`migrate`), else null. */
        public ?string $name,
        public ?int $exitCode,
        public ?string $error,
        public int $timeoutSeconds,
        /** The output passed its limit and the rest wasn't kept. */
        public bool $outputTruncated,
        /** svc_… */
        public string $service,
        /** env_… */
        public string $environment,
        /** dpl_…: the live deployment whose image ran. */
        public ?string $deployment,
        /** The server it ran on. */
        public ?string $server,
        /** Who ran it; null once the account is gone. */
        public ?string $ranBy,
        /** It came through an API token (the CLI, CI, MCP). */
        public bool $viaApi,
        public ?string $cancelledBy,
        public string $createdAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?int $durationMs,
    ) {}
}
