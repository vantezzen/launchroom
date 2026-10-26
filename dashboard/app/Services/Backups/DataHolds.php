<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\DeploymentStatus;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ImportRunStatus;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Backups\RestoreRun;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataImportRun;
use App\Services\Transfers\ImportHolds;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Why a service is held by an import or a restore (docs/14 §5.1, §12.5), for
 * its deployments (docs/04 §6 "Imports and restores win"): a hold wins over
 * a rollout. While one holds a service, a deployment of it that already
 * started is cancelled with the reason, and a queued one waits until the
 * hold ends. Server moves hold services too ({@see RestoreHolds}), but
 * redeploy them themselves, so they are not counted here.
 */
final class DataHolds
{
    /**
     * Why each held instance is held: "postgres is being imported".
     *
     * @return array<int, array{reason: string, ends: string}> held instance id => reason and what to wait for
     */
    public static function reasons(): array
    {
        $held = [];

        $imports = DataImportRun::query()
            ->whereIn('status', [ImportRunStatus::Holding->value, ImportRunStatus::Importing->value])
            ->with('serviceInstance.service')
            ->orderBy('id')
            ->get();

        foreach ($imports as $run) {
            $name = $run->serviceInstance->service->name;
            $reason = $run->subject === DataSubject::Volume ? "files are being imported into {$name}" : "{$name} is being imported";

            foreach (ImportHolds::heldBy($run) as $id) {
                $held[$id] ??= ['reason' => $reason, 'ends' => 'the import'];
            }
        }

        $restores = RestoreRun::query()
            ->where('status', RestoreRunStatus::Restoring->value)
            ->whereNotNull('target_service_instance_id')
            ->where(static fn (Builder $query) => $query
                ->whereIn('subject', [BackupSubject::Volume->value, BackupSubject::BaseBackup->value])
                ->orWhere('mode', RestoreMode::InPlace->value))
            ->orderBy('id')
            ->get();

        foreach ($restores as $run) {
            $target = ServiceInstance::query()->whereKey($run->target_service_instance_id)->with('service')->first();
            $reason = ($target?->service->name ?? 'a service').' is being restored';

            foreach (RestoreHolds::heldBy($run) as $id) {
                $held[$id] ??= ['reason' => $reason, 'ends' => 'the restore'];
            }
        }

        return $held;
    }

    /**
     * Why $instanceId is held, or null.
     *
     * @return array{reason: string, ends: string}|null
     */
    public static function for(int $instanceId): ?array
    {
        return self::reasons()[$instanceId] ?? null;
    }

    /**
     * The message of a deployment cancelled for a hold.
     *
     * @param  array{reason: string, ends: string}  $hold
     */
    public static function cancelMessage(array $hold): string
    {
        return "The deployment was cancelled because {$hold['reason']}. Deploy again once {$hold['ends']} has finished.";
    }

    /**
     * Advances the unfinished deployments of instances whose hold starts or
     * ends right away instead of at the next sweep: a started one is
     * cancelled, a queued one starts once the hold is over.
     *
     * @param  list<int>  $instanceIds
     */
    public static function advanceDeployments(array $instanceIds): void
    {
        if ($instanceIds === []) {
            return;
        }

        $ids = Deployment::query()
            ->whereIn('service_instance_id', $instanceIds)
            ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))
            ->pluck('id');

        foreach ($ids as $id) {
            if (is_int($id)) {
                dispatch(new AdvanceDeploymentJob($id))->afterCommit();
            }
        }
    }
}
