<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\DeletionRequestStatus;
use App\Models\Backups\DeletionRequest;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Support\Refusal;

/**
 * Starts the final backups of a deletion request (docs/14 §9.13). A backup
 * that can't start blocks the request with the reason.
 */
final readonly class StartFinalBackups
{
    public function __construct(
        private StartServiceBackup $start,
    ) {}

    /**
     * @param  list<ServiceInstance>  $instances
     */
    public function handle(DeletionRequest $request, array $instances, ?User $user, AuditActor $actor): void
    {
        $ids = [];
        $problems = [];

        foreach ($instances as $instance) {
            $runs = $this->start->handle($instance, BackupReason::PreDelete, $user, $actor);

            if ($runs instanceof Refusal) {
                $problems[] = "{$instance->service->name}: {$runs->message}";

                continue;
            }

            foreach ($runs as $run) {
                $ids[] = $run->id;
            }
        }

        $request->forceFill(['backup_run_ids' => $ids])->save();

        if ($problems !== []) {
            $request->transitionTo(DeletionRequestStatus::Blocked, 'No final backup could be taken of '.implode(' ', $problems));
        }
    }
}
