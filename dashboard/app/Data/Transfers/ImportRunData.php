<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\ImportSourceKind;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataImportRun;
use App\Services\Backups\HoldBlocker;
use App\Services\Backups\RestoreHolds;
use App\Services\Transfers\ImportHolds;
use Spatie\LaravelData\Data;

/**
 * An import as the UI, the API and the CLI see it (docs/14 §12.7). The
 * source label never carries a user or password.
 */
final class ImportRunData extends Data
{
    /**
     * @param  list<string>  $waitingFor
     */
    public function __construct(
        public string $id,
        public DataSubject $subject,
        public ImportRunStatus $status,
        public ImportSourceKind $sourceKind,
        public string $sourceLabel,
        public bool $replace,
        public bool $stopDependents,
        public ?TransferVolumeData $volume,
        /** The live progress line of the running task. */
        public ?string $step,
        public ?ImportResultData $result,
        public ?string $error,
        public ?string $createdAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        /** Whether a safety snapshot was taken first. */
        public bool $safetySnapshot = false,
        /** The transfer the file came from (to try again without uploading). */
        public ?string $transferId = null,
        /** While holding: the services it waits for, still running. */
        public array $waitingFor = [],
        /** While holding: why they still run, when it's their server (offline, busy with an earlier change). */
        public ?string $waitingOn = null,
    ) {}

    public static function fromRun(DataImportRun $run): self
    {
        $run->loadMissing(['volume', 'daemonTask', 'transfer']);
        $task = $run->daemonTask;
        $running = $run->status === ImportRunStatus::Holding ? RestoreHolds::running(ImportHolds::heldBy($run)) : [];

        return new self(
            $run->public_id,
            $run->subject,
            $run->status,
            $run->source_kind,
            $run->source_label,
            $run->replace,
            $run->stop_dependents,
            TransferVolumeData::fromVolume($run->volume),
            $run->status === ImportRunStatus::Importing && $task !== null ? $task->progress : null,
            ImportResultData::fromResult($run->result),
            $run->error,
            $run->created_at?->toIso8601String(),
            $run->started_at?->toIso8601String(),
            $run->finished_at?->toIso8601String(),
            $run->safety_backup_run_id !== null,
            $run->transfer?->public_id,
            self::names($running),
            HoldBlocker::describe($running),
        );
    }

    /**
     * @param  list<int>  $running
     * @return list<string>
     */
    private static function names(array $running): array
    {
        /** @var list<string> $names */
        $names = $running === [] ? [] : ServiceInstance::query()->whereKey($running)->with('service')->get()
            ->map(static fn (ServiceInstance $instance): string => $instance->service->name)
            ->unique()->sort()->values()->all();

        return $names;
    }
}
