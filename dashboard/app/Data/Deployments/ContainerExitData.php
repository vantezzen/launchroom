<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\WorkloadFailureCode;
use Spatie\LaravelData\Data;

/**
 * `placements.last_exit`: the container's last exit and its final output
 * lines, masked (WorkloadEvent `exited`, D68). Also a create or start
 * failure (`create_failed`): then `code` is null and `failure` says why.
 */
final class ContainerExitData extends Data
{
    /**
     * @param  list<array{time: string, stream: string, text: string}>  $output
     */
    public function __construct(
        public string $at,
        public ?int $code = null,
        public bool $oomKilled = false,
        public array $output = [],
        /** The signal that ended the process (codes above 128). */
        public ?int $signal = null,
        /** launchroom stopped or killed it itself (stop, replace, liveness). */
        public bool $byLaunchroom = false,
        /** Why the start command couldn't run, typed by launchroomd (create_failed, or exec failures on exited). */
        public ?WorkloadFailureCode $failure = null,
        /** launchroomd's message for a typed failure (masked). */
        public ?string $message = null,
    ) {}
}
