<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\Transfers\DataImportRun;
use App\Services\Backups\RestoreHolds;
use App\Services\Transfers\ImportHolds;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fails imports still `holding` after `transfers.hold_timeout_seconds`
 * (docs/14 §12.5): one whose task couldn't be created, one left over from
 * an older release, or one the tick didn't move. Nothing was imported, and
 * the held services start again. From the transfers tick
 * ({@see AdvanceImports}) and the sweep ({@see SweepTransfers}), in case the
 * tick itself is stuck.
 */
final readonly class FailStuckImports
{
    public function handle(): int
    {
        $failed = 0;

        /** @var list<int> $ids */
        $ids = DataImportRun::query()
            ->where('status', ImportRunStatus::Holding->value)
            ->where('holding_since', '<', now()->subSeconds(config()->integer('launchroom.transfers.hold_timeout_seconds')))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            try {
                $failed += DB::transaction(static function () use ($id): bool {
                    $run = DataImportRun::query()->whereKey($id)->with(['serviceInstance.service', 'volume'])->lockForUpdate()->first();

                    if (! $run instanceof DataImportRun || ! ImportHolds::overdue($run)) {
                        return false;
                    }

                    $held = ImportHolds::heldBy($run);
                    $running = $run->subject === DataSubject::Database ? RestoreHolds::running($held) : [];
                    $run->transitionTo(ImportRunStatus::Failed, ImportHolds::overdueReason($run, $running));
                    ImportHolds::release($run, $held);

                    return true;
                }) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $failed;
    }
}
