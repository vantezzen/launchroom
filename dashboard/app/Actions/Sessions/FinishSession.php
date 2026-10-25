<?php

declare(strict_types=1);

namespace App\Actions\Sessions;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\DaemonTaskStatus;
use App\Enums\Sessions\DatabaseQueryStatus;
use App\Enums\Sessions\InteractiveSessionStatus;
use App\Enums\Sessions\SessionEndReason;
use App\Models\DaemonTask;
use App\Models\Sessions\DatabaseQuery;
use App\Models\Sessions\InteractiveSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closes a session when its daemon task reached a terminal status (docs/03
 * §11.1): the end fields from the SessionResult (or a reason the dashboard
 * knows: cancelled before a server took it, lease lost), the statements'
 * outcomes in the query history, and the "ended" audit entry with duration,
 * reason, exit code and byte counts. Idempotent.
 */
final readonly class FinishSession
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(DaemonTask $task): void
    {
        DB::transaction(function () use ($task): void {
            $session = InteractiveSession::query()
                ->where('daemon_task_id', $task->id)
                ->where('team_id', $task->team_id)
                ->lockForUpdate()
                ->first();

            if (! $session instanceof InteractiveSession || ! $session->status->isLive()) {
                return;
            }

            $result = is_array($task->result['session'] ?? null) ? $task->result['session'] : [];
            $reason = SessionEndReason::tryFrom(is_string($result['endReason'] ?? null) ? $result['endReason'] : '')
                ?? match ($task->status) {
                    DaemonTaskStatus::Cancelled => $session->ended_by_id !== null ? SessionEndReason::Closed : SessionEndReason::Ended,
                    default => SessionEndReason::Error,
                };

            if ($session->ended_by_id !== null && $reason === SessionEndReason::Ended && $session->ended_by_id === $session->user_id) {
                $reason = SessionEndReason::Closed;
            }

            $endedAt = CarbonImmutable::now();
            $startedAt = $this->time($result['startedAt'] ?? null);
            $exitCode = is_int($result['exitCode'] ?? null) ? $result['exitCode'] : null;

            $session->forceFill([
                'started_at' => $startedAt ?? $session->started_at,
                'ended_at' => $endedAt,
                'end_reason' => $reason,
                'exit_code' => $exitCode,
                'bytes_in' => is_int($result['bytesIn'] ?? null) ? $result['bytesIn'] : 0,
                'bytes_out' => is_int($result['bytesOut'] ?? null) ? $result['bytesOut'] : 0,
                'session_key' => null,
            ]);
            $session->transitionTo(InteractiveSessionStatus::Ended);

            $this->recordCommands($session, is_array($result['commands'] ?? null) ? $result['commands'] : []);

            if ($session->started_at === null || in_array($reason, [SessionEndReason::Error, SessionEndReason::Refused, SessionEndReason::NeverConnected], true)) {
                // Why a session never started is otherwise only in the task (docs/03 §11.4).
                Log::warning('Interactive session ended before it was used.', [
                    'session' => $session->public_id,
                    'kind' => $session->kind->value,
                    'reason' => $reason->value,
                    'task' => $task->public_id,
                    'taskStatus' => $task->status->value,
                    'error' => $task->error === null ? null : mb_substr($task->error, 0, 1000),
                ]);
            }

            $session->loadMissing('serviceInstance.service', 'server');
            $this->audit->handle(new AuditEntryData(
                action: $session->kind->endedAction(),
                actor: $session->server !== null ? AuditActor::server($session->server) : AuditActor::system(),
                teamId: $session->team_id,
                subjectType: 'service',
                subjectId: $session->serviceInstance->service->public_id,
                meta: array_filter([
                    'session' => $session->public_id,
                    'reason' => $reason->value,
                    'seconds' => (int) round(abs($endedAt->diffInSeconds($startedAt ?? $session->created_at ?? $endedAt))),
                    'exitCode' => $exitCode,
                    'bytesIn' => $session->bytes_in,
                    'bytesOut' => $session->bytes_out,
                    'endedBy' => $session->ended_by_id === null ? null : ($session->ended_by_id === $session->user_id ? 'user' : 'admin'),
                ], static fn (mixed $value): bool => $value !== null),
            ));
        });
    }

    /**
     * @param  array<mixed>  $commands
     */
    private function recordCommands(InteractiveSession $session, array $commands): void
    {
        $byOp = [];

        foreach ($commands as $command) {
            if (is_array($command) && is_string($command['op'] ?? null)) {
                $byOp[$command['op']] = $command;
            }
        }

        DatabaseQuery::query()
            ->where('interactive_session_id', $session->id)
            ->where('status', DatabaseQueryStatus::Sent->value)
            ->get()
            ->each(static function (DatabaseQuery $query) use ($byOp): void {
                $outcome = $byOp[$query->op_id] ?? null;
                $status = is_array($outcome) ? DatabaseQueryStatus::tryFrom(is_string($outcome['status'] ?? null) ? $outcome['status'] : '') : null;

                // Never answered: the session ended before the command ran.
                $query->finish(
                    $status ?? DatabaseQueryStatus::Error,
                    is_array($outcome) && ($outcome['truncated'] ?? false) === true,
                    is_array($outcome) && is_int($outcome['rows'] ?? null) ? $outcome['rows'] : null,
                    is_array($outcome) && is_int($outcome['durationMs'] ?? null) ? $outcome['durationMs'] : null,
                );
            });
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
