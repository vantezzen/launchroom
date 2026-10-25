<?php

declare(strict_types=1);

namespace App\Actions\RemoteAccess;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\RemoteAccess\RemoteAccessEndReason;
use App\Enums\RemoteAccess\RemoteAccessStatus;
use App\Models\DaemonTask;
use App\Models\RemoteAccess\RemoteAccess;
use App\Services\RemoteAccess\AccessEventAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Closes an access when its task reached a terminal status (docs/03 §12.1):
 * the end fields from the AccessResult (or what the dashboard knows), the
 * connections after the last heartbeat, the "ended" audit entry, and the
 * password wiped. Idempotent.
 */
final readonly class FinishRemoteAccess
{
    public function __construct(
        private RecordAuditEvent $audit,
        private AccessEventAudit $events,
    ) {}

    public function handle(DaemonTask $task): void
    {
        DB::transaction(function () use ($task): void {
            $access = RemoteAccess::query()
                ->where('daemon_task_id', $task->id)
                ->where('team_id', $task->team_id)
                ->lockForUpdate()
                ->first();

            if (! $access instanceof RemoteAccess || ! $access->status->isLive()) {
                return;
            }

            $task->refresh();
            $result = is_array($task->result['access'] ?? null) ? $task->result['access'] : [];
            $reason = RemoteAccessEndReason::tryFrom(is_string($result['endReason'] ?? null) ? $result['endReason'] : '')
                ?? ($access->ended_by_id !== null ? RemoteAccessEndReason::Ended : RemoteAccessEndReason::Error);

            $this->events->record($access, is_array($result['events'] ?? null) ? $result['events'] : []);

            $endedAt = CarbonImmutable::now();
            $access->forceFill([
                'ended_at' => $endedAt,
                'end_reason' => $reason,
                'active_connections' => 0,
                'connections' => max($access->connections, is_int($result['connections'] ?? null) ? $result['connections'] : 0),
                'auth_failures' => is_int($result['authFailures'] ?? null) ? $result['authFailures'] : $access->auth_failures,
                'bytes_in' => is_int($result['bytesIn'] ?? null) ? $result['bytesIn'] : 0,
                'bytes_out' => is_int($result['bytesOut'] ?? null) ? $result['bytesOut'] : 0,
                'password' => null,
            ]);
            $access->transitionTo(RemoteAccessStatus::Ended);

            $access->loadMissing('serviceInstance.service', 'server');
            $from = $access->listening_at ?? $access->created_at ?? $endedAt;
            $this->audit->handle(new AuditEntryData(
                action: $access->kind->endedAction(),
                actor: $access->server !== null ? AuditActor::server($access->server) : AuditActor::system(),
                teamId: $access->team_id,
                subjectType: 'service',
                subjectId: $access->serviceInstance->service->public_id,
                meta: array_filter([
                    'access' => $access->public_id,
                    'reason' => $reason->value,
                    'seconds' => (int) round(abs($endedAt->diffInSeconds($from))),
                    'connections' => $access->connections,
                    'authFailures' => $access->auth_failures,
                    'bytesIn' => $access->bytes_in,
                    'bytesOut' => $access->bytes_out,
                    'endedBy' => $access->ended_by_id === null ? null : ($access->ended_by_id === $access->user_id ? 'user' : 'admin'),
                    'error' => $reason === RemoteAccessEndReason::Error || $reason === RemoteAccessEndReason::Refused ? $this->clip($task->error) : null,
                ], static fn (mixed $value): bool => $value !== null),
            ));
        });
    }

    private function clip(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        try {
            return mb_substr($error, 0, 300);
        } catch (Throwable) {
            return null;
        }
    }
}
