<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Enums\Backups\DeletionRequestStatus;
use App\Models\Backups\DeletionRequest;
use App\Models\User;
use App\Services\Backups\FinalBackups;
use Illuminate\Support\Facades\DB;

/**
 * Takes the final backups of a blocked deletion again (docs/14 §9.13).
 */
final readonly class RetryFinalBackup
{
    public function __construct(
        private FinalBackups $finalBackups,
        private ContinueDeletion $continue,
    ) {}

    public function handle(DeletionRequest $request, User $user, AuditActor $actor): void
    {
        $request = DB::transaction(function () use ($request, $user, $actor): DeletionRequest {
            $request = DeletionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->finalBackups->retry($request, $this->finalBackups->instancesFor($request), $user, $actor);

            return $request;
        });

        if ($request->status === DeletionRequestStatus::Ready) {
            $this->continue->handle($request, $actor);
        }
    }
}
