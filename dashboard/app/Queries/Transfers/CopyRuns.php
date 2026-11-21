<?php

declare(strict_types=1);

namespace App\Queries\Transfers;

use App\Data\Transfers\CopyRunData;
use App\Data\Transfers\CopyStepData;
use App\Data\Transfers\ImportResultData;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Transfers\CopyRunStatus;
use App\Enums\Transfers\CopySourcePoint;
use App\Enums\Transfers\CopyStep;
use App\Enums\Transfers\CopyStepState;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataCopyRun;
use App\Models\Transfers\DataImportRun;
use App\Models\User;
use App\Services\Holds\HoldBlocker;
use App\Services\Holds\Holds;
use App\Services\Holds\ImportHolds;
use App\Support\ByteSize;
use Illuminate\Support\Facades\Gate;

/**
 * A copy between environments as the UI sees it (docs/14 §14.2): five steps
 * (copy the source, safety snapshot, stop apps, load the data, start apps
 * again) read from its export and import runs.
 */
final class CopyRuns
{
    public static function view(DataCopyRun $run, User $user): CopyRunData
    {
        $run->loadMissing(['source.environment', 'target.environment', 'target.service', 'targetVolume', 'exportRun.transfer', 'importRun.safetyBackup', 'importRun.daemonTask', 'actor']);
        $import = $run->importRun;
        $result = ImportResultData::fromResult($import?->result);

        return new CopyRunData(
            id: $run->public_id,
            status: $run->status,
            subject: $run->subject,
            sourceEnvironmentName: $run->source->environment->name,
            sourceEnvironmentKind: $run->source->environment->kind,
            targetEnvironmentName: $run->target->environment->name,
            mountPath: $run->targetVolume?->mount_path,
            point: $run->source_point,
            snapshotAt: $run->snapshot_at?->toIso8601String(),
            sizeBytes: $run->size_bytes,
            steps: self::steps($run, $import),
            error: $run->error,
            actorName: $run->actor?->name,
            createdAt: $run->created_at?->toIso8601String(),
            finishedAt: $run->finished_at?->toIso8601String(),
            cancellable: self::cancellable($run, $import) && Gate::forUser($user)->allows('cancel', $run),
            safetySnapshot: $run->status === CopyRunStatus::Failed && $import?->safetyBackup?->status === BackupRunStatus::Succeeded ? $import->safetyBackup->snapshot_id : null,
            warnings: $result instanceof ImportResultData ? $result->warnings : [],
        );
    }

    private static function cancellable(DataCopyRun $run, ?DataImportRun $import): bool
    {
        return $run->status === CopyRunStatus::Exporting
            || ($run->status === CopyRunStatus::Importing && $import instanceof DataImportRun && $import->status->isWaiting());
    }

    /**
     * @return list<CopyStepData>
     */
    private static function steps(DataCopyRun $run, ?DataImportRun $import): array
    {
        $source = $run->source->environment->name;
        $target = $run->target->environment->name;
        $stopping = self::stopping($run);
        $order = CopyStep::cases();
        $reached = array_search(self::currentStep($run, $import), $order, true);
        $state = static function (CopyStep $step) use ($order, $reached, $run): CopyStepState {
            $index = array_search($step, $order, true);

            if ($run->status === CopyRunStatus::Succeeded || $index < $reached) {
                return CopyStepState::Done;
            }

            if ($index > $reached) {
                return CopyStepState::Waiting;
            }

            return match ($run->status) {
                CopyRunStatus::Failed => CopyStepState::Failed,
                CopyRunStatus::Cancelled => CopyStepState::Cancelled,
                default => CopyStepState::Current,
            };
        };

        $snapshot = $state(CopyStep::Snapshot);

        // A safety snapshot that couldn't be taken (no storage) is skipped, not done.
        if ($import instanceof DataImportRun && $import->safety_backup_run_id === null && $snapshot === CopyStepState::Done) {
            $snapshotStep = new CopyStepData(CopyStep::Snapshot, "Safety snapshot of {$target}", CopyStepState::Skipped, 'No backup storage, so there is no snapshot');
        } else {
            $snapshotStep = new CopyStepData(CopyStep::Snapshot, "Safety snapshot of {$target}", $snapshot, self::snapshotDetail($import));
        }

        $export = new CopyStepData(CopyStep::Export, "Copy the data from {$source}", $state(CopyStep::Export), self::exportDetail($run));
        $load = new CopyStepData(CopyStep::Import, "Load the data into {$target}", $state(CopyStep::Import), self::importDetail($import));

        // Nothing else uses the data: no stop and start steps.
        if ($stopping === []) {
            return [$export, $snapshotStep, $load];
        }

        $names = self::names($stopping);
        // Held services start again after a failed or cancelled import too.
        $restarted = in_array($run->status, [CopyRunStatus::Failed, CopyRunStatus::Cancelled], true) && $import?->holding_since !== null;

        return [
            $export,
            $snapshotStep,
            new CopyStepData(CopyStep::Stop, "Stop {$names}", $state(CopyStep::Stop), self::stopDetail($import)),
            $load,
            $restarted
                ? new CopyStepData(CopyStep::Start, "Start {$names} again", CopyStepState::Done, 'Started again after the import ended')
                : new CopyStepData(CopyStep::Start, "Start {$names} again", $state(CopyStep::Start)),
        ];
    }

    private static function currentStep(DataCopyRun $run, ?DataImportRun $import): CopyStep
    {
        if (! $import instanceof DataImportRun) {
            return CopyStep::Export;
        }

        if ($run->status === CopyRunStatus::Succeeded) {
            return CopyStep::Start;
        }

        return match ($import->status) {
            ImportRunStatus::WaitingForSafetyBackup => CopyStep::Snapshot,
            ImportRunStatus::Holding => CopyStep::Stop,
            ImportRunStatus::Importing, ImportRunStatus::Succeeded => CopyStep::Import,
            // Where it ended: the task ran, or the hold began, or neither.
            ImportRunStatus::Failed, ImportRunStatus::Cancelled => match (true) {
                $import->started_at !== null => CopyStep::Import,
                $import->holding_since !== null => CopyStep::Stop,
                default => CopyStep::Snapshot,
            },
        };
    }

    private static function exportDetail(DataCopyRun $run): ?string
    {
        $export = $run->exportRun;
        $bytes = (int) $export?->transfer?->size_bytes;
        $from = $run->source_point === CopySourcePoint::Live ? 'Live data' : 'Backup';

        if ($export?->status === ExportRunStatus::Preparing) {
            return $bytes > 0 ? ByteSize::format($bytes).' so far' : "{$from}, starting on the server";
        }

        $size = $run->size_bytes ?? ($bytes > 0 ? $bytes : null);

        return $size === null ? null : "{$from}, ".ByteSize::format($size);
    }

    private static function snapshotDetail(?DataImportRun $import): ?string
    {
        $safety = $import?->safetyBackup;

        return match ($safety?->status) {
            BackupRunStatus::Succeeded => 'Snapshot '.substr((string) $safety->snapshot_id, 0, 8),
            BackupRunStatus::Failed => 'The snapshot failed, so nothing was replaced',
            default => null,
        };
    }

    private static function stopDetail(?DataImportRun $import): ?string
    {
        if (! $import instanceof DataImportRun || $import->status !== ImportRunStatus::Holding) {
            return null;
        }

        $running = Holds::running(ImportHolds::heldBy($import));

        if ($running === []) {
            return null;
        }

        $names = self::serviceNames($running);
        $blocker = HoldBlocker::describe($running);

        return 'Waiting for '.self::names($names).' to stop'.($blocker === null ? '' : ". {$blocker}");
    }

    private static function importDetail(?DataImportRun $import): ?string
    {
        if ($import?->status === ImportRunStatus::Importing) {
            return $import->daemonTask?->progress;
        }

        $result = ImportResultData::fromResult($import?->result);

        if (! $result instanceof ImportResultData) {
            return null;
        }

        $what = $import?->subject === DataSubject::Volume
            ? "{$result->files} files"
            : $result->tables.($result->tables === 1 ? ' table' : ' tables');

        return $what.', '.ByteSize::format($result->sizeBytes);
    }

    /**
     * The services that stop while the data is replaced.
     *
     * @return list<string>
     */
    private static function stopping(DataCopyRun $run): array
    {
        $target = $run->target;
        $ids = $run->subject === DataSubject::Volume ? [$target->id] : Holds::dependentsOf($target);

        return self::serviceNames($ids);
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private static function serviceNames(array $ids): array
    {
        $names = ServiceInstance::query()->whereKey($ids)->with('service')->get()
            ->map(static fn (ServiceInstance $instance): string => $instance->service->name)
            ->unique()->sort()->all();

        return array_values($names);
    }

    /**
     * @param  list<string>  $names
     */
    private static function names(array $names): string
    {
        if (count($names) <= 2) {
            return implode(' and ', $names);
        }

        return implode(', ', array_slice($names, 0, -1)).' and '.end($names);
    }
}
