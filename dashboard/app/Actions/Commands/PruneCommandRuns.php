<?php

declare(strict_types=1);

namespace App\Actions\Commands;

use App\Enums\Commands\CommandRunStatus;
use App\Models\Commands\CommandRun;
use App\Models\LogChunk;
use Illuminate\Support\Facades\DB;

/**
 * Retention of one-off commands (D105): ended runs older than
 * `launchroom.commands.retention_days`, and the oldest beyond
 * `keep_per_service` of a service, go with their output. The audit log
 * keeps who ran what. Returns how many runs were deleted.
 */
final class PruneCommandRuns
{
    private const int BATCH = 500;

    public function handle(): int
    {
        $ended = array_map(static fn (CommandRunStatus $status): string => $status->value, CommandRunStatus::terminal());
        $cutoff = now()->subDays(config()->integer('launchroom.commands.retention_days'));
        $keep = config()->integer('launchroom.commands.keep_per_service');

        $old = CommandRun::query()->whereIn('status', $ended)->where('created_at', '<', $cutoff)->pluck('id')->all();

        // Ranked per service, newest first: everything past `keep`.
        $surplus = DB::table('command_runs')
            ->fromSub(
                DB::table('command_runs')
                    ->whereIn('status', $ended)
                    ->selectRaw('id, row_number() OVER (PARTITION BY service_instance_id ORDER BY id DESC) AS rank'),
                'ranked',
            )
            ->where('rank', '>', $keep)
            ->pluck('id')
            ->all();

        $ids = array_values(array_unique(array_filter([...$old, ...$surplus], is_int(...))));

        foreach (array_chunk($ids, self::BATCH) as $batch) {
            DB::transaction(static function () use ($batch): void {
                $tasks = CommandRun::query()->whereIn('id', $batch)->whereNotNull('daemon_task_id')->pluck('daemon_task_id')->all();
                LogChunk::query()->whereIn('daemon_task_id', $tasks)->delete();
                CommandRun::query()->whereIn('id', $batch)->delete();
            });
        }

        return count($ids);
    }
}
