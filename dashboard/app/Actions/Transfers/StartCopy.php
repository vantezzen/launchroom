<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Transfers\Inputs\StartCopyInput;
use App\Data\Transfers\Inputs\StartExportInput;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\EnvironmentKind;
use App\Enums\Transfers\CopySourcePoint;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ImportRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\RestoreRun;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataCopyRun;
use App\Models\Transfers\DataImportRun;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Transfers\CopyRules;
use App\Services\Transfers\ImportInspections;
use App\Services\Transfers\ImportTarget;
use Illuminate\Support\Facades\DB;

/**
 * Starts a copy of the same service's data from another environment of the
 * project (D110, docs/14 §14): the source is exported first (live, or one of
 * its backups); {@see AdvanceCopies} imports it into the target once it is
 * ready. The controller has authorized the pair (DataCopyRunPolicy).
 *
 * The typed confirmation (the target environment's name) is required unless
 * this user's latest inspection of the target found it empty; a production
 * target also needs `replaceProduction`.
 */
final readonly class StartCopy
{
    public function __construct(
        private StartExport $export,
        private ImportInspections $inspections,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $source, ServiceInstance $target, StartCopyInput $input, User $user, AuditActor $actor): DataCopyRun
    {
        $target->loadMissing(['service', 'environment', 'volumes']);
        $source->loadMissing(['service', 'environment', 'volumes']);

        $refusal = CopyRules::targetRefusal($target) ?? CopyRules::sourceRefusal($source, $target);

        if ($refusal !== null) {
            throw DomainRuleViolation::on('source', $refusal);
        }

        $this->confirm($target, $input, $user);

        $subject = ImportTarget::for($target)->subject;
        $targetVolume = $subject === DataSubject::Volume ? ImportTarget::for($target)->volume($input->volume, 'copy files') : null;
        $sourceVolume = $targetVolume instanceof Volume ? CopyRules::pairedVolume($source, $targetVolume) : null;

        if ($targetVolume instanceof Volume && ! $sourceVolume instanceof Volume) {
            throw DomainRuleViolation::on('volume', "{$source->service->name} has no volume at {$targetVolume->mount_path} in {$source->environment->name}.");
        }

        $backup = $this->backup($source, $input, $sourceVolume);

        if ($input->point === CopySourcePoint::Live && $subject === DataSubject::Database && ! DatabaseWorkloads::servingPlacement($source) instanceof Placement) {
            throw DomainRuleViolation::on('point', "{$source->service->name} isn’t running in {$source->environment->name}. Copy one of its backups instead.");
        }

        return DB::transaction(function () use ($source, $target, $input, $user, $actor, $subject, $targetVolume, $sourceVolume, $backup): DataCopyRun {
            ServiceInstance::query()->whereKey($target->id)->lockForUpdate()->first();
            $this->assertIdle($target);

            $export = $this->export->handle($source, new StartExportInput($sourceVolume?->public_id, $backup?->snapshot_id), $user, $actor);

            $run = new DataCopyRun([
                'team_id' => $target->team_id,
                'source_service_instance_id' => $source->id,
                'target_service_instance_id' => $target->id,
                'subject' => $subject,
                'source_volume_id' => $sourceVolume?->id,
                'target_volume_id' => $targetVolume?->id,
                'source_point' => $input->point,
                'snapshot_id' => $backup?->snapshot_id,
                'snapshot_at' => $backup?->finished_at,
                'actor_id' => $user->id,
            ]);
            $run->export_run_id = $export->id;
            $run->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DataCopyStarted,
                actor: $actor,
                teamId: $target->team_id,
                subjectType: 'service',
                subjectId: $target->service->public_id,
                meta: array_filter([
                    'copy' => $run->public_id,
                    'from' => $source->environment->public_id,
                    'to' => $target->environment->public_id,
                    'subject' => $run->subject->value,
                    'volume' => $targetVolume?->public_id,
                    'point' => $input->point->value,
                    'snapshot' => $backup?->snapshot_id === null ? null : substr($backup->snapshot_id, 0, 16),
                ], static fn (mixed $value): bool => $value !== null),
            ));

            return $run;
        });
    }

    private function confirm(ServiceInstance $target, StartCopyInput $input, User $user): void
    {
        $name = $target->environment->name;

        if ($target->environment->kind === EnvironmentKind::Production && ! $input->replaceProduction) {
            throw DomainRuleViolation::on('replaceProduction', "Confirm that this replaces {$name}’s data.");
        }

        $inspection = $this->inspections->latest($target, $user);
        $empty = $inspection?->status === 'done' && $inspection->empty === true;
        $needed = ! $empty || $target->environment->kind === EnvironmentKind::Production;

        if ($needed && ($input->confirmation === null || ! hash_equals($name, $input->confirmation))) {
            throw DomainRuleViolation::on('confirmation', "Type {$name} to confirm. The copy replaces {$target->service->name}’s data there.");
        }
    }

    /**
     * The backup a backup point reads, or null for live data.
     */
    private function backup(ServiceInstance $source, StartCopyInput $input, ?Volume $volume): ?BackupRun
    {
        if (! $input->point->readsBackup()) {
            return null;
        }

        $destination = BackupDestination::forService($source);

        $run = $destination instanceof BackupDestination ? BackupRun::query()
            ->where('service_instance_id', $source->id)
            ->where('destination_id', $destination->id)
            ->where('subject', $volume instanceof Volume ? BackupSubject::Volume->value : BackupSubject::Database->value)
            ->when($volume instanceof Volume, static fn ($query) => $query->where('volume_id', $volume?->id))
            ->where('status', BackupRunStatus::Succeeded->value)
            ->whereNotNull('snapshot_id')
            ->when($input->point === CopySourcePoint::Backup, static fn ($query) => $query->where('snapshot_id', 'like', $input->snapshot.'%'))
            ->latest('finished_at')
            ->latest('id')
            ->first() : null;

        if (! $run instanceof BackupRun) {
            throw DomainRuleViolation::on('point', $input->point === CopySourcePoint::Backup
                ? 'This backup isn’t one of '.$source->environment->name.'’s. Pick one from the list.'
                : "{$source->service->name} has no backup in {$source->environment->name} yet. Copy the live data instead.");
        }

        return $run;
    }

    private function assertIdle(ServiceInstance $target): void
    {
        if (DataCopyRun::openInto($target->id)) {
            throw DomainRuleViolation::conflict('A copy into this service is already running.');
        }

        $importing = DataImportRun::query()->where('service_instance_id', $target->id)->whereIn('status', ImportRunStatus::nonTerminal())->exists();
        $restoring = RestoreRun::query()->where('target_service_instance_id', $target->id)->whereIn('status', RestoreRunStatus::nonTerminal())->exists();

        if ($importing || $restoring) {
            throw DomainRuleViolation::conflict('An import or restore of this service is running. Copy once it has finished.');
        }
    }
}
