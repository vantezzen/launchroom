<?php

declare(strict_types=1);

namespace App\Data\Logs;

use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Start (or renew) a live runtime log tail for an environment, optionally
 * for one service.
 */
final class LogTailData extends Data
{
    public function __construct(
        #[Regex('/^svc_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $service = null,
    ) {}
}
