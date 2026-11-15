<?php

declare(strict_types=1);

namespace App\Actions\Environments;

use App\Actions\Backups\RequestFinalBackups;
use App\Actions\Deployments\TearDownInstance;
use App\Data\Audit\AuditActor;
use App\Data\Backups\DeletionSubject;
use App\Enums\ActorType;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\Backups\DeletionRequestStatus;
use App\Events\NodeStateInvalidated;
use App\Models\Backups\DeletionRequest;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Backups\FinalBackups;
use App\Services\Volumes\VolumeRetirements;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * The teardown every environment or project deletion runs (DeleteEnvironment,
 * DeleteProject; previews through DeleteEnvironment), inside the caller's
 * transaction with the subject and its instances locked:
 *
 * 1. final backups first (docs/14 §9.13, RequestFinalBackups): while they
 *    run or after one failed, nothing is deleted;
 * 2. nothing is deleted while another backup or restore of one of its
 *    services runs (`Busy`: ask again once it finished);
 * 3. the instances' workloads leave the NodeStates and their deployments end
 *    (TearDownInstance); volumes stay on their servers for the
 *    retention period, then are deleted explicitly (docs/05 §1.1), backed-up
 *    ones once their final backup exists or the deletion was overridden;
 * 4. $deleteRows deletes the subject's rows and writes its audit entry;
 * 5. the deletion request completes, and the instances' servers and the
 *    team's edges (which drop the routes) re-render after commit.
 */
final readonly class TearDownInstances
{
    public function __construct(
        private TearDownInstance $teardown,
        private VolumeRetirements $retirements,
        private FinalBackups $finalBackups,
        private RequestFinalBackups $requestFinalBackups,
    ) {}

    /**
     * @param  Collection<int, ServiceInstance>  $instances
     * @param  'environment_deleted'|'project_deleted'  $retireReason
     * @param  Closure(?DeletionRequest): void  $deleteRows
     */
    public function handle(DeletionSubject $subject, Collection $instances, AuditActor $actor, bool $skipFinalBackup, bool $backupsInFlight, string $retireReason, Closure $deleteRows): DeletionOutcome
    {
        $user = $actor->type === ActorType::User && $actor->id !== null ? User::query()->find($actor->id) : null;
        $request = $this->requestFinalBackups->handle($subject->type, $subject->id, $subject->publicId, $subject->name, $subject->teamId, $instances, $user, $actor, $skipFinalBackup);

        if ($request instanceof DeletionRequest && ! $request->status->allowsDeletion()) {
            return DeletionOutcome::forStatus($request->status);
        }

        if ($backupsInFlight) {
            return DeletionOutcome::Busy;
        }

        $servers = [];

        foreach ($instances as $instance) {
            $servers = [...$servers, ...$this->teardown->handle($instance)];
        }

        $this->retirements->retire($instances, $retireReason, $this->finalBackups->runsByVolume($request), $request?->status === DeletionRequestStatus::Overridden, $request?->overridden_by);

        $deleteRows($request);
        $request?->transitionTo(DeletionRequestStatus::Completed);

        NodeStateInvalidated::edges($subject->teamId, $servers);

        return DeletionOutcome::Deleted;
    }
}
