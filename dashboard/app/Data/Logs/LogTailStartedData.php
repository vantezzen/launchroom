<?php

declare(strict_types=1);

namespace App\Data\Logs;

use Spatie\LaravelData\Data;

/**
 * Answer to starting live runtime logs: how many replicas are followed and
 * when the viewer renews the tails (LogTails::RENEW_SECONDS).
 */
final class LogTailStartedData extends Data
{
    public function __construct(
        public int $following,
        public int $renewAfterSeconds,
    ) {}
}
