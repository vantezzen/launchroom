<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Transfers\TransferDirection;
use App\Enums\Transfers\TransferStatus;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Uses a download link (docs/14 §12.5): it is consumed here, once, for the
 * user it was made for and before it expires; the download is audited. The
 * caller streams the returned transfer. Anything else is "not found".
 */
final readonly class OpenDownload
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(string $token, User $user, AuditActor $actor, bool $viaToken): DataTransfer
    {
        if (preg_match('/^lr_dl_[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw (new ModelNotFoundException)->setModel(DataTransfer::class);
        }

        return DB::transaction(function () use ($token, $user, $actor, $viaToken): DataTransfer {
            $transfer = DataTransfer::query()
                ->where('link_token_hash', hash('sha256', $token))
                ->whereIn('team_id', $user->teamIdsQuery())
                ->with('serviceInstance.service')
                ->lockForUpdate()
                ->first();

            $valid = $transfer instanceof DataTransfer
                && $transfer->direction === TransferDirection::Download
                && $transfer->status === TransferStatus::Ready
                && $transfer->link_user_id === $user->id
                && $transfer->link_expires_at !== null
                && $transfer->link_expires_at->isFuture()
                && $transfer->expires_at->isFuture()
                // Still allowed to export (owners and admins, docs/14 §12.5).
                && $user->hasTeamPermission($transfer->team_id, Permission::ManageBackups);

            if (! $valid) {
                throw (new ModelNotFoundException)->setModel(DataTransfer::class);
            }

            // One use.
            $transfer->forceFill([
                'link_token_hash' => null,
                'link_expires_at' => null,
                'downloads' => $transfer->downloads + 1,
                'last_used_at' => now(),
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DataExportDownloaded,
                actor: $actor,
                teamId: $transfer->team_id,
                subjectType: 'service',
                subjectId: $transfer->serviceInstance->service->public_id,
                meta: ['transfer' => $transfer->public_id, 'sizeBytes' => $transfer->size_bytes, 'via' => $viaToken ? 'token' : 'browser'],
            ));

            return $transfer;
        });
    }
}
