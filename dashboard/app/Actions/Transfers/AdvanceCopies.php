<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Backups\TakeSafetySnapshot;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\Transfers\CopyRunStatus;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\RestoreRun;
use App\Models\Server;
use App\Models\Transfers\DataCopyRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\Volume;
use App\Services\Transfers\CopyRules;
use App\Services\Transfers\ImportTarget;
use App\Services\Transfers\TransferRuns;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves copies between environments forward (D110, docs/14 §14.2), from
 * the transfers tick and right after an export or import run finished:
 *
 * - exporting: once the source's export is ready, the import into the
 *   target starts (replace, stop dependents, safety snapshot first) →
 *   importing; a failed export fails the copy with the target unchanged;
 * - importing: the import's outcome is the copy's.
 *
 * Idempotent: terminal copies never change again.
 */
final readonly class AdvanceCopies
{
    public function __construct(
        private TakeSafetySnapshot $safety,
        private TransferRuns $runs,
        private RecordAuditEvent $audit,
        private AdvanceImports $imports,
    ) {}

    public function handle(): int
    {
        /** @var list<int> $ids */
        $ids = DataCopyRun::query()->whereIn('status', CopyRunStatus::nonTerminal())->orderBy('id')->pluck('id')->all();
        $advanced = 0;
        $started = false;

        foreach ($ids as $id) {
            try {
                $outcome = DB::transaction(fn (): ?string => $this->advance($id));
                $advanced += $outcome === null ? 0 : 1;
                $started = $started || $outcome === 'import';
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($started) {
            $this->imports->handle();
        }

        return $advanced;
    }

    /**
     * @return 'import'|'ended'|null what changed
     */
    private function advance(int $id): ?string
    {
        $run = DataCopyRun::query()->whereKey($id)->with(['source.environment', 'target.service', 'target.environment', 'targetVolume.server', 'exportRun.transfer', 'importRun', 'actor'])->lockForUpdate()->first();

        if (! $run instanceof DataCopyRun || $run->status->isTerminal()) {
            return null;
        }

        if ($run->status === CopyRunStatus::Exporting) {
            return $this->afterExport($run);
        }

        $import = $run->importRun;

        return match ($import?->status) {
            ImportRunStatus::Succeeded => $this->end($run, CopyRunStatus::Succeeded),
            // Before the task ran (snapshot, hold) the import's own sentence says it all.
            ImportRunStatus::Failed => $this->end($run, CopyRunStatus::Failed, $import->started_at === null
                ? ($import->error ?? 'The copy couldn’t start loading the data.')
                : 'Loading the data failed: '.($import->error ?? 'the import ended early.')),
            ImportRunStatus::Cancelled => $this->end($run, CopyRunStatus::Cancelled),
            null => $this->end($run, CopyRunStatus::Failed, 'The import of the copy disappeared.'),
            default => null,
        };
    }

    /**
     * @return 'import'|'ended'|null
     */
    private function afterExport(DataCopyRun $run): ?string
    {
        $export = $run->exportRun;
        $source = $run->source->environment->name;
        $target = $run->target;
        $unchanged = "{$target->environment->name} is unchanged.";

        if ($export === null || in_array($export->status, [ExportRunStatus::Failed, ExportRunStatus::Expired], true)) {
            return $this->end($run, CopyRunStatus::Failed, "Copying the data out of {$source} failed: ".rtrim($export->error ?? 'the export ended early', '.').". {$unchanged}");
        }

        if ($export->status !== ExportRunStatus::Ready) {
            return null;
        }

        $transfer = $export->transfer;

        if (! $transfer instanceof DataTransfer) {
            return $this->end($run, CopyRunStatus::Failed, "The copy of {$source}’s data is gone. {$unchanged}");
        }

        $refusal = CopyRules::targetRefusal($target) ?? $this->busy($run);

        if ($refusal !== null) {
            return $this->end($run, CopyRunStatus::Failed, "{$refusal} {$unchanged}");
        }

        try {
            $volume = $run->targetVolume;
            $server = $run->subject === DataSubject::Volume ? $volume?->server : ImportTarget::for($target)->placement('copy data')->server;
        } catch (DomainRuleViolation $e) {
            return $this->end($run, CopyRunStatus::Failed, $e->getMessage().' '.$unchanged);
        }

        if (! $server instanceof Server || ($run->subject === DataSubject::Volume && ! $volume instanceof Volume)) {
            return $this->end($run, CopyRunStatus::Failed, "The volume to copy into no longer exists. {$unchanged}");
        }

        // Kept while the copy needs it (the safety snapshot can take a while).
        $transfer->forceFill(['expires_at' => now()->addDay(), 'last_used_at' => now()])->save();

        $safety = $this->safety->handle($target, BackupReason::PreRestore, $run->actor, AuditActor::system(), $volume)->first();
        $import = $this->runs->import(
            $target,
            $run->subject,
            $volume,
            $transfer,
            "Copied from {$source}",
            $server->id,
            replace: true,
            stopDependents: true,
            user: $run->actor,
            safety: $safety,
        );

        $run->import_run_id = $import->id;
        $run->size_bytes = $transfer->size_bytes;
        $run->transitionTo(CopyRunStatus::Importing);

        return 'import';
    }

    /**
     * Why the target is busy with something else, or null.
     */
    private function busy(DataCopyRun $run): ?string
    {
        $importing = DataImportRun::query()->where('service_instance_id', $run->target_service_instance_id)->whereIn('status', ImportRunStatus::nonTerminal())->exists();
        $restoring = RestoreRun::query()->where('target_service_instance_id', $run->target_service_instance_id)->whereIn('status', RestoreRunStatus::nonTerminal())->exists();

        return $importing || $restoring ? 'An import or restore of '.$run->target->service->name.' started meanwhile.' : null;
    }

    /**
     * @return 'ended'
     */
    private function end(DataCopyRun $run, CopyRunStatus $status, ?string $error = null): string
    {
        $run->transitionTo($status, $error);

        // The exported file isn't needed any more; the sweep removes it.
        $transferId = $run->exportRun?->transfer_id;

        if ($transferId !== null) {
            DataTransfer::query()->whereKey($transferId)->update(['expires_at' => now()]);
        }

        $this->audit->handle(new AuditEntryData(
            action: $status === CopyRunStatus::Cancelled ? AuditAction::DataCopyCancelled : AuditAction::DataCopyFinished,
            actor: AuditActor::system(),
            teamId: $run->team_id,
            subjectType: 'service',
            subjectId: $run->target->service->public_id,
            meta: array_filter([
                'copy' => $run->public_id,
                'outcome' => $status->value,
                'sizeBytes' => $run->size_bytes,
                'durationMs' => $run->created_at === null ? null : (int) $run->created_at->diffInMilliseconds(now()),
            ], static fn (mixed $value): bool => $value !== null),
        ));

        return 'ended';
    }
}
