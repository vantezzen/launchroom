<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerStatus;
use App\Models\DaemonTask;
use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * A task pinned to a server that went offline would wait forever: backup
 * runs stay "Waiting" until the server is back, then run hours late. Once a
 * pending task waited longer than its type's limit
 * (`launchroom.tasks.pending_max_age_seconds`) while its server isn't
 * online, it is cancelled with the reason, so whatever waits on it (a
 * backup run, a release step) ends and the next schedule takes over.
 * Pending tasks can't fail (DaemonTaskStatus), so they end cancelled with
 * the reason as their error.
 */
final class StalePendingTasks
{
    /**
     * @return int the number of tasks cancelled
     */
    public function cancel(): int
    {
        /** @var array<string, int> $limits */
        $limits = config()->array('launchroom.tasks.pending_max_age_seconds');
        $default = $limits['default'] ?? 6 * 3600;
        $shortest = min([$default, ...array_values($limits)]);

        return DB::transaction(static function () use ($limits, $default, $shortest): int {
            $tasks = DaemonTask::query()
                ->where('status', DaemonTaskStatus::Pending->value)
                ->where('pinned_to_server', true)
                ->where('created_at', '<', now()->subSeconds($shortest))
                ->whereIn('server_id', Server::query()->select('id')->where('status', '!=', ServerStatus::Online->value))
                ->with('server:id,name')
                ->lock('for update skip locked')
                ->limit(100)
                ->get();

            $cancelled = 0;

            foreach ($tasks as $task) {
                $limit = $limits[$task->type->value] ?? $default;

                if ($task->created_at === null || $task->created_at->greaterThan(now()->subSeconds($limit))) {
                    continue;
                }

                $name = $task->server instanceof Server ? $task->server->name : 'Its server';
                $task->forceFill(['error' => "{$name} was offline for over ".self::duration($limit).', so this '.self::what($task->type).' didn’t run.']);
                $task->transitionTo(DaemonTaskStatus::Cancelled);
                $cancelled++;
            }

            return $cancelled;
        });
    }

    private static function duration(int $seconds): string
    {
        return $seconds >= 3600
            ? ($seconds % 3600 === 0 ? ($seconds / 3600).' '.($seconds === 3600 ? 'hour' : 'hours') : round($seconds / 3600, 1).' hours')
            : intdiv($seconds, 60).' minutes';
    }

    private static function what(DaemonTaskType $type): string
    {
        return match ($type) {
            DaemonTaskType::Backup, DaemonTaskType::BackupSnapshots, DaemonTaskType::BackupMaintenance => 'backup',
            DaemonTaskType::VerifyRestore => 'restore check',
            DaemonTaskType::Restore => 'restore',
            DaemonTaskType::RunOnce => 'command',
            DaemonTaskType::SelfUpdate => 'update',
            default => 'task',
        };
    }
}
