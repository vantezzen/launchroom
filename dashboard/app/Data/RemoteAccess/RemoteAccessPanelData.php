<?php

declare(strict_types=1);

namespace App\Data\RemoteAccess;

use App\Data\Sessions\SessionReplicaData;
use App\Enums\RemoteAccess\RemoteAccessKind;
use Spatie\LaravelData\Data;

/**
 * The "Connect via SSH", "Connect with SFTP" and "Temporary access" panel
 * (docs/08 §15).
 */
final class RemoteAccessPanelData extends Data
{
    /**
     * @param  list<SessionReplicaData>  $replicas
     * @param  list<int>  $durations  minutes the user can pick
     * @param  list<RemoteAccessData>  $accesses  live ones first, then the latest ended
     */
    public function __construct(
        public RemoteAccessKind $kind,
        public bool $available,
        public ?string $unavailableReason,
        /** The address the dashboard saw this request come from, as a prefix. */
        public ?string $requesterPrefix,
        public array $replicas,
        public ?string $engineLabel,
        public int $idleMinutes,
        public array $durations,
        public int $defaultDuration,
        public array $accesses,
        /** What the access opens, for the panel's header. */
        public string $serviceName = '',
        public string $environmentName = '',
        /** Volume access (D67): the volume, its server and whether it may open writable. */
        public ?string $volumeId = null,
        public ?string $mountPath = null,
        public ?string $volumeServer = null,
        public bool $writable = false,
    ) {}
}
