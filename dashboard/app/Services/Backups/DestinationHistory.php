<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\BackupVerification;
use App\Models\Backups\RestoreRun;
use App\Models\Backups\WalArchive;
use Illuminate\Database\Eloquent\Builder;

/**
 * What happens to backup history when storage is removed and added again
 * (docs/14 §3): removing keeps every run, restore, point-in-time archive and
 * verification pointing at the removed row (read-only "storage removed");
 * adding storage of the same scope takes over the schedules that pointed at
 * removed storage (their cron and retention survive), and adding the same
 * bucket and folder again reconnects the history recorded there. Callers own
 * the transaction.
 */
final class DestinationHistory
{
    /**
     * Schedules of $destination's scope whose storage was removed now use
     * $destination. Team schedules move only to the team's default.
     *
     * @return int how many moved
     */
    public static function adoptSchedules(BackupDestination $destination): int
    {
        if ($destination->team_id !== null && ! $destination->is_default) {
            return 0;
        }

        return BackupSchedule::query()
            ->when(
                $destination->team_id === null,
                static fn (Builder $query) => $query->whereNull('team_id'),
                static fn (Builder $query) => $query->where('team_id', $destination->team_id),
            )
            ->whereIn('destination_id', BackupDestination::onlyTrashed()->select('id'))
            ->update(['destination_id' => $destination->id]);
    }

    /**
     * History recorded on removed storage of the same scope at the same
     * bucket and folder belongs to $destination again.
     *
     * @return int how many removed destinations were reconnected
     */
    public static function reconnect(BackupDestination $destination): int
    {
        $location = BackupDestination::location($destination->endpoint, $destination->bucket, $destination->prefix);
        $removed = BackupDestination::onlyTrashed()
            ->where('bucket', $destination->bucket)
            ->when(
                $destination->team_id === null,
                static fn (Builder $query) => $query->whereNull('team_id'),
                static fn (Builder $query) => $query->where('team_id', $destination->team_id),
            )
            ->get()
            ->filter(static fn (BackupDestination $old): bool => BackupDestination::location($old->endpoint, $old->bucket, $old->prefix) === $location)
            ->modelKeys();

        if ($removed === []) {
            return 0;
        }

        foreach ([BackupRun::class, RestoreRun::class, WalArchive::class, BackupVerification::class] as $model) {
            $model::query()->whereIn('destination_id', $removed)->update(['destination_id' => $destination->id]);
        }

        return count($removed);
    }
}
