<?php

declare(strict_types=1);

namespace App\Actions\RemoteAccess;

use App\Data\Protocol\V1\AccessStatus;
use App\Enums\RemoteAccess\RemoteAccessStatus;
use App\Models\DaemonTask;
use App\Models\RemoteAccess\RemoteAccess;
use App\Services\RemoteAccess\AccessEventAudit;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Stores what launchroomd reports about a running access (docs/03 §12.1):
 * the port, host key and certificate the panel shows, the counters, and
 * one audit entry per authenticated connection, per address with failed
 * logins and for a lockout (docs/09 R39). Runs inside the heartbeat's
 * transaction.
 */
final readonly class RecordAccessHeartbeat
{
    public function __construct(
        private AccessEventAudit $events,
    ) {}

    public function handle(DaemonTask $task, ?AccessStatus $status): void
    {
        if (! $status instanceof AccessStatus) {
            return;
        }

        $access = RemoteAccess::query()
            ->where('daemon_task_id', $task->id)
            ->where('team_id', $task->team_id)
            ->lockForUpdate()
            ->with('serviceInstance.service', 'server')
            ->first();

        if (! $access instanceof RemoteAccess || ! $access->status->isLive()) {
            return;
        }

        $access->forceFill(array_filter([
            'port' => $status->port,
            'host_key' => is_array($status->hostKey) ? $status->hostKey : null,
            'certificate' => is_array($status->certificate) ? $status->certificate : null,
            'database_name' => is_string($status->database) ? $status->database : null,
            'private_addresses' => is_array($status->privateAddresses) ? array_slice($status->privateAddresses, 0, 4) : null,
            'behind_nat' => is_bool($status->behindNat) ? $status->behindNat : null,
            'volume_owner' => is_array($status->owner) ? ['uid' => $status->owner['uid'], 'gid' => $status->owner['gid']] : null,
            'active_connections' => $status->activeConnections,
            'last_activity_at' => is_string($status->lastActivityAt) ? $this->time($status->lastActivityAt) : null,
            'listening_at' => $access->listening_at ?? now(),
        ], static fn (mixed $value): bool => $value !== null))->save();

        // Listening: an access with keys has no password to seal at lease
        // time, so its first heartbeat is what opens it.
        if ($access->status === RemoteAccessStatus::Pending) {
            $access->transitionTo(RemoteAccessStatus::Open);
        }

        $this->events->record($access, is_array($status->events) ? $status->events : []);
    }

    private function time(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
