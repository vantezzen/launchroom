<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One replica of `security_investigations.replicas` (docs/22 §7): where it
 * runs, its inspect_container task and how far that got.
 */
final class InvestigatedReplicaData extends Data
{
    public function __construct(
        public string $workload,
        public string $server,
        public string $serverName,
        public int $slot,
        public ?string $task,
        /** pending | done | failed | offline | unsupported */
        public string $state,
        public ?string $message = null,
    ) {}
}
