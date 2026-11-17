<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Data;

/**
 * One run of a scheduled cron service (`cron_runs`, G19): when it started
 * and ended, how (`exitMeaning`: ExitMeaning's sentence, null for a clean
 * exit). `running`: no end reported yet.
 */
final class CronRunData extends Data
{
    public function __construct(
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?int $exitCode,
        public bool $oomKilled,
        public bool $running,
        public bool $failed,
        public ?string $exitMeaning,
    ) {}
}
