<?php

declare(strict_types=1);

namespace App\Services\RemoteAccess;

use App\Data\RemoteAccess\RemoteAccessData;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\User;
use App\Support\Network\ServerAddress;
use App\Support\RemoteAccess\SshPublicKey;
use Illuminate\Support\Facades\Gate;

/**
 * Builds what the panel shows of an access (docs/08 §15). Credentials only
 * for the opener while the access is live.
 */
final class RemoteAccessView
{
    public static function data(RemoteAccess $access, User $user): RemoteAccessData
    {
        $access->loadMissing('user:id,name', 'server:id,name,public_ipv4,public_ipv6', 'volume:id,public_id,mount_path');
        $owner = is_array($access->volume_owner) ? $access->volume_owner : null;
        $live = $access->status->isLive();
        $mine = $live && Gate::forUser($user)->allows('useCredentials', $access);
        $host = self::host($access);
        $hostKey = is_array($access->host_key) ? $access->host_key : null;
        $certificate = is_array($access->certificate) ? $access->certificate : null;

        $fingerprints = [];

        foreach ($access->public_keys as $line) {
            $key = SshPublicKey::parse($line);

            if ($key instanceof SshPublicKey) {
                $fingerprints[] = $key->fingerprint();
            }
        }

        $hostLabel = $host !== null && str_contains($host, ':') ? '['.$host.']' : $host;

        return new RemoteAccessData(
            id: $access->public_id,
            kind: $access->kind,
            status: $access->status,
            isMine: $access->user_id === $user->id,
            userName: $access->user->name,
            replicaLabel: 'Replica '.($access->slot + 1),
            serverName: $access->server?->name,
            engine: $access->engine?->value,
            engineLabel: $access->engine?->label(),
            username: $access->username,
            password: $mine ? $access->password : null,
            authMethod: $access->public_keys === [] ? 'password' : 'key',
            keyFingerprints: $fingerprints,
            allowFrom: $access->allow_from,
            allowWrites: $access->allow_writes,
            host: $host,
            port: $access->port,
            database: $access->database_name,
            hostKeyFingerprint: is_string($hostKey['sha256'] ?? null) ? $hostKey['sha256'] : null,
            knownHostsLine: $access->kind->usesSshKeys() && $hostLabel !== null && $access->port !== null && is_string($hostKey['publicKey'] ?? null)
                ? '['.trim($hostLabel, '[]').']:'.$access->port.' '.$hostKey['publicKey']
                : null,
            certificateSha256: is_string($certificate['sha256'] ?? null) ? $certificate['sha256'] : null,
            certificatePem: $live && is_string($certificate['pem'] ?? null) ? $certificate['pem'] : null,
            behindNat: $access->behind_nat,
            privateAddresses: $access->private_addresses,
            activeConnections: $live ? $access->active_connections : 0,
            connections: $access->connections,
            idleSeconds: $access->idle_seconds,
            createdAt: ($access->created_at ?? now())->toIso8601String(),
            expiresAt: $access->expires_at->toIso8601String(),
            lastActivityAt: $access->last_activity_at?->toIso8601String(),
            endedAt: $access->ended_at?->toIso8601String(),
            endReason: $access->end_reason?->sentence(),
            canEnd: $live && Gate::forUser($user)->allows('end', $access),
            volumeId: $access->volume?->public_id,
            mountPath: $access->volume?->mount_path,
            fileOwner: $owner === null ? null : $owner['uid'].':'.$owner['gid'],
            endedByOther: $access->ended_by_id !== null && $access->ended_by_id !== $access->user_id,
        );
    }

    /**
     * Where clients connect: the server's public address, else a private one
     * launchroomd reported.
     */
    public static function host(RemoteAccess $access): ?string
    {
        $server = $access->server;
        $public = $server === null ? null : (ServerAddress::ipv4($server->public_ipv4) ?? ServerAddress::ipv6($server->public_ipv6));

        return $public ?? ($access->private_addresses[0] ?? null);
    }

    /**
     * The addresses the certificate names (spec.hosts).
     *
     * @return list<string>
     */
    public static function certificateHosts(?string $ipv4, ?string $ipv6): array
    {
        return array_values(array_filter([ServerAddress::ipv4($ipv4), ServerAddress::ipv6($ipv6)]));
    }
}
