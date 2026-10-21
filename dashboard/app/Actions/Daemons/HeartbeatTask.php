<?php

declare(strict_types=1);

namespace App\Actions\Daemons;

use App\Data\Protocol\V1\TaskHeartbeat;
use App\Data\Protocol\V1\TaskHeartbeatResult;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Sessions\SessionGate;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Extends a lease held by this server for this attempt.
 */
final readonly class HeartbeatTask
{
    public function __construct(
        private SessionGate $sessions,
    ) {}

    public function handle(Server $server, DaemonTask $task, TaskHeartbeat $heartbeat): TaskHeartbeatResult
    {
        return DB::transaction(function () use ($server, $task, $heartbeat): TaskHeartbeatResult {
            $task = DaemonTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($task->server_id !== $server->id || $task->status !== DaemonTaskStatus::Leased || $task->attempt !== $heartbeat->attempt) {
                throw DomainRuleViolation::conflict('This server does not hold a lease for this task attempt.');
            }

            $expires = now()->addSeconds(config()->integer('launchroom.daemon.lease_seconds'));

            if ($task->deadline_at !== null && $expires->greaterThan($task->deadline_at)) {
                $expires = $task->deadline_at;
            }

            $task->forceFill([
                'lease_expires_at' => $expires,
                'progress' => $heartbeat->progress instanceof Optional ? $task->progress : $heartbeat->progress,
            ])->save();

            // Interactive sessions are re-authorized on every heartbeat (docs/03 §11.3).
            if ($task->type === DaemonTaskType::Session && $task->cancel_requested_at === null && ! $this->sessions->stillAllowed($task)) {
                $task->forceFill(['cancel_requested_at' => now()])->save();
            }

            return new TaskHeartbeatResult($expires->toImmutable(), $task->cancel_requested_at !== null);
        });
    }
}
