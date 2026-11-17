<?php

declare(strict_types=1);

namespace App\Data\Logs;

use Spatie\LaravelData\Data;

/**
 * Answer to starting live runtime logs: how many replicas are followed,
 * when the viewer renews the tails (LogTails::RENEW_SECONDS), and why a
 * replica has no live tail or isn't running (a server offline, a replica
 * that exited or never started), one sentence each.
 */
final class LogTailStartedData extends Data
{
    /**
     * @param  list<string>  $notes
     */
    public function __construct(
        public int $following,
        public int $renewAfterSeconds,
        public array $notes = [],
    ) {}
}
