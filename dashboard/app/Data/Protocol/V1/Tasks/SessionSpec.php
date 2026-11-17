<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\V1\Sealed;
use App\Enums\DaemonTaskType;

/**
 * An interactive console, data or recovery session (protocol SessionSpec,
 * docs/03 §11). `key` is sealed at lease time.
 */
final class SessionSpec extends TaskSpec
{
    /**
     * @param  'console'|'data'|'recovery'  $kind
     */
    public function __construct(
        public string $session,
        public string $kind,
        public string $workload,
        public string $channel,
        public string $idleTimeout,
        public ?string $shell = null,
        public ?int $cols = null,
        public ?int $rows = null,
        public ?bool $record = null,
        public ?string $engine = null,
        public ?Sealed $key = null,
        /** Recovery sessions: mount the volumes writable (audited). */
        public ?bool $allowChanges = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::Session;
    }
}
