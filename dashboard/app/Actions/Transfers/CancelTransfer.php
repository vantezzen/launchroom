<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Enums\Transfers\ImportRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Services\Transfers\TransferStore;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an upload and deletes its chunks (docs/14 §12.7). Refused while an
 * import still needs the file.
 */
final readonly class CancelTransfer
{
    public function __construct(
        private TransferStore $store,
    ) {}

    public function handle(DataTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $inUse = DataImportRun::query()
                ->where('transfer_id', $transfer->id)
                ->whereIn('status', [ImportRunStatus::WaitingForSafetyBackup->value, ImportRunStatus::Holding->value, ImportRunStatus::Importing->value])
                ->exists();

            if ($inUse) {
                throw DomainRuleViolation::conflict('An import is still reading this file. Wait for it to finish.');
            }

            $transfer->delete();
        });

        $this->store->delete($transfer);
    }
}
