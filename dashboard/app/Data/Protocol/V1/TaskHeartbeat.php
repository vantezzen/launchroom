<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * POST /daemon/v1/tasks/{id}/heartbeat (protocol/schema/v1/task-heartbeat.json).
 */
final class TaskHeartbeat extends Data
{
    public function __construct(
        #[In(['launchroom.vantezzen.io/v1'])]
        public string $apiVersion,
        #[In(['TaskHeartbeat'])]
        public string $kind,
        #[Min(1)]
        public int $attempt,
        #[Max(256)]
        public string|Optional $progress = new Optional,
    ) {}
}
