<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Enums\Backups\DeletionOutcome;
use App\Models\Backups\DeletionRequest;
use App\Models\User;
use App\Services\Backups\FinalBackups;
use Illuminate\Support\Facades\DB;

/**
 * "Delete without final backup" (docs/14 §9.13): an authorized owner/admin
 * overrides a blocked (or still running) final backup. It
 * is audited (`backup.final_backup_skipped`); environments and projects are
 * deleted right away, a staged service removal goes with the next Deploy.
 * Its volumes are deleted after the retention period like any other.
 */
final readonly class SkipFinalBackup
{
    public function __construct(
        private FinalBackups $finalBackups,
        private ContinueDeletion $continue,
    ) {}

    public function handle(DeletionRequest $request, User $user, AuditActor $actor): ?DeletionOutcome
    {
        $request = DB::transaction(function () use ($request, $user, $actor): DeletionRequest {
            $request = DeletionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->finalBackups->skip($request, $user, $actor);

            return $request;
        });

        return $this->continue->handle($request, $actor);
    }
}
