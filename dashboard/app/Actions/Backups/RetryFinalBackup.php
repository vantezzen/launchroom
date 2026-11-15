<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Enums\Backups\DeletionRequestStatus;
use App\Models\Backups\DeletionRequest;
use App\Models\User;
use App\Services\Backups\FinalBackups;
use Illuminate\Support\Facades\DB;

final readonly class RetryFinalBackup
{
    public function __construct(
        private FinalBackups $finalBackups,
        private StartFinalBackups $start,
        private ContinueDeletion $continue,
    ) {}

    public function handle(DeletionRequest $request, User $user, AuditActor $actor): void
    {
        $request = DB::transaction(function () use ($request, $user, $actor): DeletionRequest {
            $request = DeletionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->retry($request, $user, $actor);

            return $request;
        });

        if ($request->status === DeletionRequestStatus::Ready) {
            $this->continue->handle($request, $actor);
        }
    }

    /** blocked → backing_up, or ready when nothing needs a backup any more. */
    private function retry(DeletionRequest $request, User $user, AuditActor $actor): void
    {
        if ($request->status !== DeletionRequestStatus::Blocked) {
            return;
        }

        $needed = $this->finalBackups->needed($this->finalBackups->instancesFor($request));
        $request->forceFill(['backup_run_ids' => []]);
        $request->transitionTo(DeletionRequestStatus::BackingUp);

        if ($needed === []) {
            $request->transitionTo(DeletionRequestStatus::Ready);

            return;
        }

        $this->start->handle($request, $needed, $user, $actor);
    }
}
