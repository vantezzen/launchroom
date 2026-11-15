<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Backups\TakeSafetySnapshot;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\DatabaseSource;
use App\Data\Transfers\Inputs\StartImportInput;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\TransferPurpose;
use App\Enums\Transfers\TransferStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\RestoreRun;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Transfers\FileSniffer;
use App\Services\Transfers\ImportTarget;
use App\Services\Transfers\SniffResult;
use App\Services\Transfers\SourceUrl;
use App\Services\Transfers\TransferRuns;
use App\Services\Transfers\TransferTasks;
use App\Support\Crypto\TaskSealer;
use Illuminate\Support\Facades\DB;

/**
 * Starts an import (docs/14 §12.5): from an uploaded file or a connection
 * URL into a database, or from an archive into a volume. Replacing needs
 * the service's slug typed and takes a safety snapshot first (databases:
 * {@see TakeSafetySnapshot}; volumes get one either way, merging overwrites
 * files too). {@see AdvanceImports} holds the services and starts the task.
 * A connection URL is sealed to the target server for a reserved task id
 * right here and never stored readable.
 */
final readonly class StartImport
{
    public function __construct(
        private TakeSafetySnapshot $safety,
        private TransferRuns $runs,
        private RecordAuditEvent $audit,
        private AdvanceImports $advance,
    ) {}

    public function handle(ServiceInstance $instance, StartImportInput $input, User $user, AuditActor $actor): DataImportRun
    {
        $instance->loadMissing(['service', 'environment']);
        $target = ImportTarget::for($instance);

        // A stopped service runs nothing to import into (D68).
        if ($instance->stopped_at !== null) {
            throw DomainRuleViolation::on('service', 'This service is stopped. Start it, then import.');
        }

        if (! $target->importSupported() || ! $target->subject instanceof DataSubject) {
            throw DomainRuleViolation::on('service', 'Importing into this service isn’t supported.');
        }

        $hasFile = $input->transfer !== null;
        $hasUrl = $input->sourceUrl !== null && $input->sourceUrl !== '';

        if ($hasFile === $hasUrl || ($target->subject === DataSubject::Volume && $hasUrl)) {
            throw DomainRuleViolation::on('transfer', $target->subject === DataSubject::Volume ? 'Upload an archive to import.' : 'Import either an uploaded file or a connection URL.');
        }

        if ($input->replace && ($input->confirmation === null || ! hash_equals($instance->service->slug, $input->confirmation))) {
            throw DomainRuleViolation::on('confirmation', "Type {$instance->service->slug} to confirm. Importing replaces its data.");
        }

        $transfer = $hasFile ? $this->transfer($instance, (string) $input->transfer, $target) : null;
        $volume = null;
        $server = null;
        $sealed = null;
        $taskId = null;
        $label = $transfer instanceof DataTransfer ? $transfer->file_name : '';

        if ($target->subject === DataSubject::Volume) {
            $volume = $target->volume($transfer instanceof DataTransfer && $transfer->volume !== null ? $transfer->volume->public_id : $input->volume);

            if ($transfer?->volume_id !== null && $transfer->volume_id !== $volume->id) {
                throw DomainRuleViolation::on('volume', 'This archive was uploaded for another volume.');
            }

            $server = $volume->server;
        } else {
            $placement = $target->placement();
            $server = $placement->server;

            if ($hasUrl) {
                $url = SourceUrl::parse((string) $input->sourceUrl, $target->import());
                $taskId = TransferTasks::reserveTaskId();
                $sealed = TaskSealer::env($server->sealing_public_key, $server->public_id, $taskId, ['SOURCE_URL' => $url->url]);
                $label = $url->label;
            }
        }

        if (! $server instanceof Server) {
            throw DomainRuleViolation::on('service', 'This service has no server yet. Deploy it first.');
        }

        $run = DB::transaction(function () use ($instance, $input, $user, $actor, $target, $transfer, $volume, $server, $sealed, $taskId, $label): DataImportRun {
            ServiceInstance::query()->whereKey($instance->id)->lockForUpdate()->first();
            $this->assertIdle($instance);

            $safety = $volume instanceof Volume || $input->replace ? $this->safety->handle($instance, BackupReason::PreRestore, $user, $actor, $volume)->first() : null;
            $run = $this->runs->import(
                $instance,
                $target->subject,
                $volume,
                $transfer,
                $label,
                $server->id,
                $input->replace,
                $target->subject === DataSubject::Volume || $input->stopDependents,
                $user,
                stripComponents: $input->stripComponents,
                ownerUid: $input->owner?->uid,
                ownerGid: $input->owner?->gid,
                safety: $safety,
                sealedSource: $sealed,
                reservedTaskId: $taskId,
            );

            if ($transfer instanceof DataTransfer) {
                $transfer->forceFill(['last_used_at' => now()])->save();
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DataImportStarted,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: array_filter([
                    'import' => $run->public_id,
                    'environment' => $instance->environment->public_id,
                    'source' => $run->source_kind->value,
                    // The URL label never has a user or password.
                    'from' => $run->source_label,
                    'format' => $transfer?->format,
                    'volume' => $volume?->public_id,
                    'replace' => $run->replace,
                    'stopDependents' => $run->stop_dependents,
                    'safetySnapshot' => $safety?->public_id,
                ], static fn (mixed $value): bool => $value !== null),
            ));

            return $run;
        });

        $this->advance->handle();

        return $run->refresh();
    }

    private function transfer(ServiceInstance $instance, string $publicId, ImportTarget $target): DataTransfer
    {
        $transfer = DataTransfer::query()
            ->where('public_id', $publicId)
            ->where('service_instance_id', $instance->id)
            ->with('volume')
            ->first();

        $purpose = $target->subject === DataSubject::Volume ? TransferPurpose::VolumeImport : TransferPurpose::DatabaseImport;

        if (! $transfer instanceof DataTransfer || $transfer->purpose !== $purpose) {
            throw DomainRuleViolation::on('transfer', 'Upload the file to this service first.');
        }

        if ($transfer->status !== TransferStatus::Ready) {
            throw DomainRuleViolation::on('transfer', $transfer->status === TransferStatus::Receiving ? 'The upload isn’t finished yet.' : 'This upload is gone. Upload the file again.');
        }

        // The same checks as when the upload finished (the database may have changed since).
        if ($purpose === TransferPurpose::DatabaseImport && $target->database instanceof DatabaseSource) {
            $sniff = $transfer->sniff;
            $result = new SniffResult(
                $transfer->format,
                $transfer->compressed,
                in_array($sniff?->family, SniffResult::FAMILIES, true) ? $sniff->family : null,
                $sniff?->serverVersion,
                $sniff?->toolVersion,
            );
            $refusal = FileSniffer::refusal($result, $target->import(), $target->database->version, $instance->service->name);

            if ($refusal !== null) {
                throw DomainRuleViolation::on('transfer', $refusal);
            }
        }

        return $transfer;
    }

    private function assertIdle(ServiceInstance $instance): void
    {
        $importing = DataImportRun::query()
            ->where('service_instance_id', $instance->id)
            ->whereIn('status', ImportRunStatus::nonTerminal())
            ->exists();
        $restoring = RestoreRun::query()
            ->where('target_service_instance_id', $instance->id)
            ->whereIn('status', RestoreRunStatus::nonTerminal())
            ->exists();

        if ($importing || $restoring) {
            throw DomainRuleViolation::conflict('An import or restore of this service is already running.');
        }
    }
}
