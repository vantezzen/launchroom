<?php

declare(strict_types=1);

namespace App\Data\Logs;

use App\Data\Attributes\PublicIdOf;
use Spatie\LaravelData\Data;

/**
 * Start (or renew) a live runtime log tail for an environment, optionally
 * for one service.
 */
final class LogTailData extends Data
{
    public function __construct(
        #[PublicIdOf('svc')]
        public ?string $service = null,
    ) {}
}
