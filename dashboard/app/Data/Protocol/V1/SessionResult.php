<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * TaskCompletion.session (protocol/schema/v1/task-completion.json): how an
 * interactive session ended (docs/03 §11.1).
 */
final class SessionResult extends Data
{
    public const array END_REASONS = ['closed', 'exited', 'idle', 'deadline', 'disconnected', 'ended', 'never_connected', 'refused', 'error'];

    /**
     * @param  list<SessionCommandResult>|Optional  $commands
     */
    public function __construct(
        #[In(self::END_REASONS)]
        public string $endReason,
        #[Min(0)]
        public int $bytesIn,
        #[Min(0)]
        public int $bytesOut,
        /** RFC 3339 */
        #[Max(64)]
        public string|Optional $startedAt = new Optional,
        public int|null|Optional $exitCode = new Optional,
        #[Max(1000)]
        public array|Optional $commands = new Optional,
    ) {}
}
