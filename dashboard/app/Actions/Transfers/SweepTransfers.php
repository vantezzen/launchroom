<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\TransferDirection;
use App\Enums\Transfers\TransferStatus;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Services\Transfers\TransferStore;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `launchroom:transfers:sweep` (every 5 minutes, docs/14 §12.1): expired
 * uploads (unless an import still reads them) and exports are marked and
 * their chunks deleted; chunk directories of ended or deleted transfers go
 * too. Imports stuck before their task fail ({@see FailStuckImports}).
 */
final readonly class SweepTransfers
{
    public function __construct(
        private TransferStore $store,
        private FailStuckImports $stuckImports,
    ) {}

    /**
     * @return array{expired: int, removed: int}
     */
    public function handle(): array
    {
        $expired = 0;
        $active = [ImportRunStatus::WaitingForSafetyBackup->value, ImportRunStatus::Holding->value, ImportRunStatus::Importing->value];

        $due = DataTransfer::query()
            ->whereIn('status', [TransferStatus::Receiving->value, TransferStatus::Ready->value])
            ->where('expires_at', '<', now())
            ->whereNotExists(static fn (Builder $query) => $query->select(DB::raw(1))->from('data_import_runs')->whereColumn('data_import_runs.transfer_id', 'data_transfers.id')->whereIn('data_import_runs.status', $active))
            ->pluck('id');

        foreach ($due as $id) {
            DB::transaction(function () use ($id, &$expired): void {
                $transfer = DataTransfer::query()->whereKey($id)->lockForUpdate()->first();

                if (! $transfer instanceof DataTransfer || $transfer->status->isTerminal() || $transfer->expires_at->isFuture()) {
                    return;
                }

                $preparing = $transfer->direction === TransferDirection::Download && $transfer->status === TransferStatus::Receiving;
                $transfer->transitionTo($preparing ? TransferStatus::Failed : TransferStatus::Expired, $preparing ? 'The export took too long.' : null);

                foreach (DataExportRun::query()->where('transfer_id', $transfer->id)->whereIn('status', [ExportRunStatus::Preparing->value, ExportRunStatus::Ready->value])->get() as $run) {
                    $run->transitionTo($run->status === ExportRunStatus::Ready ? ExportRunStatus::Expired : ExportRunStatus::Failed, $preparing ? 'The export took too long.' : null);
                }

                $expired++;
            });
        }

        // Chunks of ended transfers and of rows that no longer exist.
        $removed = 0;
        $live = DataTransfer::query()->whereIn('status', [TransferStatus::Receiving->value, TransferStatus::Ready->value])->pluck('public_id')->all();

        foreach ($this->store->directories() as $directory) {
            if (! in_array($directory, $live, true)) {
                $this->store->deleteDirectory($directory);
                $removed++;
            }
        }

        // Imports whose run never got going are cancelled with their file (a day later).
        DataImportRun::query()
            ->where('status', ImportRunStatus::WaitingForSafetyBackup->value)
            ->where('created_at', '<', now()->subDay())
            ->get()
            ->each(static fn (DataImportRun $run) => $run->transitionTo(ImportRunStatus::Cancelled, 'The safety snapshot never finished.'));

        // Imports that couldn't start fail here too, should the tick be stuck.
        $this->stuckImports->handle();

        return ['expired' => $expired, 'removed' => $removed];
    }
}
