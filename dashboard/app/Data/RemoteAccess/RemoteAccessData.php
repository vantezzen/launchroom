<?php

declare(strict_types=1);

namespace App\Data\RemoteAccess;

use App\Enums\RemoteAccess\RemoteAccessKind;
use App\Enums\RemoteAccess\RemoteAccessStatus;
use Spatie\LaravelData\Data;

/**
 * One temporary access as the panel shows it. `password` is only set for
 * the person who opened it while it is live (docs/09 R39).
 */
final class RemoteAccessData extends Data
{
    /**
     * @param  list<string>  $allowFrom
     * @param  list<string>  $keyFingerprints
     * @param  list<string>  $privateAddresses
     */
    public function __construct(
        public string $id,
        public RemoteAccessKind $kind,
        public RemoteAccessStatus $status,
        public bool $isMine,
        public string $userName,
        public string $replicaLabel,
        public ?string $serverName,
        public ?string $engine,
        public ?string $engineLabel,
        public string $username,
        public ?string $password,
        /** `key` or `password`. */
        public string $authMethod,
        public array $keyFingerprints,
        public array $allowFrom,
        public bool $allowWrites,
        /** The address to connect to (public, else private), null until known. */
        public ?string $host,
        public ?int $port,
        public ?string $database,
        public ?string $hostKeyFingerprint,
        /** `[host]:port ssh-ed25519 …` for known_hosts. */
        public ?string $knownHostsLine,
        public ?string $certificateSha256,
        public ?string $certificatePem,
        public bool $behindNat,
        public array $privateAddresses,
        public int $activeConnections,
        public int $connections,
        public int $idleSeconds,
        public string $createdAt,
        public string $expiresAt,
        public ?string $lastActivityAt,
        public ?string $endedAt,
        public ?string $endReason,
        public bool $canEnd,
        /** Volume access (D67): the volume, where the service mounts it, and who files belong to. */
        public ?string $volumeId = null,
        public ?string $mountPath = null,
        /** `uid:gid` the SFTP helper reads and writes as, once known. */
        public ?string $fileOwner = null,
        /** Someone other than the opener (an owner or admin) ended it. */
        public bool $endedByOther = false,
    ) {}
}
