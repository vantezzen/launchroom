<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use Spatie\LaravelData\Data;

/**
 * The Console tab (docs/08 §15): where a console can go and recent sessions.
 */
final class ConsoleTabData extends Data
{
    /**
     * @param  list<SessionReplicaData>  $replicas
     * @param  list<string>  $shells  allow-listed shells; "" = automatic
     * @param  list<SessionHistoryItemData>  $sessions
     */
    public function __construct(
        public bool $available,
        /** Why a console can't be opened right now (one sentence), or null. */
        public ?string $unavailableReason,
        public array $replicas,
        public array $shells,
        public bool $record,
        public int $idleMinutes,
        public int $maxMinutes,
        public array $sessions,
    ) {}
}
