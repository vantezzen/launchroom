<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\DaemonTaskStatus;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\TransferDirection;
use App\Enums\Transfers\TransferStatus;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Support\PublicId;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Which transfers a server's launchroomd may read or write (docs/14 §12.2):
 * only those whose import or export run's task this server holds right now
 * (leased, not finished).
 */
final class DaemonTransfers
{
    /**
     * A file an import task on $server reads, or null: an upload, or the
     * export of a server move (another server's export of the same service
     * instance, docs/14 §13.2). $gone is set for a transfer that ended (410).
     */
    public function readable(Server $server, string $publicId, bool &$gone = false): ?DataTransfer
    {
        $gone = false;
        $transfer = $this->find($server, $publicId, null);

        if (! $transfer instanceof DataTransfer) {
            return null;
        }

        $held = DataImportRun::query()
            ->where('transfer_id', $transfer->id)
            ->where('status', ImportRunStatus::Importing->value)
            ->whereIn('daemon_task_id', $this->leasedBy($server))
            ->exists();

        if (! $held) {
            return null;
        }

        if ($transfer->status !== TransferStatus::Ready) {
            $gone = true;

            return null;
        }

        return $transfer;
    }

    /**
     * A download an export task on $server writes, or null.
     */
    public function writable(Server $server, string $publicId, bool &$gone = false): ?DataTransfer
    {
        $gone = false;
        $transfer = $this->find($server, $publicId, TransferDirection::Download);

        if (! $transfer instanceof DataTransfer) {
            return null;
        }

        $held = DataExportRun::query()
            ->where('transfer_id', $transfer->id)
            ->where('status', ExportRunStatus::Preparing->value)
            ->whereIn('daemon_task_id', $this->leasedBy($server))
            ->exists();

        if (! $held) {
            return null;
        }

        if ($transfer->status !== TransferStatus::Receiving) {
            $gone = true;

            return null;
        }

        return $transfer;
    }

    private function find(Server $server, string $publicId, ?TransferDirection $direction): ?DataTransfer
    {
        if (! PublicId::isValid($publicId, DataTransfer::publicIdPrefix())) {
            return null;
        }

        return DataTransfer::query()
            ->where('public_id', $publicId)
            ->where('team_id', $server->team_id)
            ->when($direction instanceof TransferDirection, static fn ($query) => $query->where('direction', $direction?->value))
            ->first();
    }

    /**
     * @return Builder ids of the tasks $server holds now
     */
    private function leasedBy(Server $server): Builder
    {
        return DaemonTask::query()
            ->select('id')
            ->where('server_id', $server->id)
            ->where('status', DaemonTaskStatus::Leased->value)
            ->getQuery();
    }
}
