<?php

declare(strict_types=1);

namespace App\Queries\Transfers;

use App\Data\Services\DatabaseSource;
use App\Data\Transfers\CopyRunData;
use App\Data\Transfers\DependentServiceData;
use App\Data\Transfers\ExportRunData;
use App\Data\Transfers\ImportFormatData;
use App\Data\Transfers\ServiceTransfersData;
use App\Data\Transfers\TransferVolumeData;
use App\Enums\Transfers\DataSubject;
use App\Models\Backups\BackupDestination;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataCopyRun;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\User;
use App\Models\Volume;
use App\Services\Holds\Holds;
use App\Services\Transfers\DatabasePreparations;
use App\Services\Transfers\ImportTarget;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the Move data section's data (docs/14 §12.6).
 */
final class ServiceTransfersView
{
    public function for(ServiceInstance $instance, User $user): ServiceTransfersData
    {
        $instance->loadMissing(['service', 'volumes']);
        $target = ImportTarget::for($instance);
        $gate = Gate::forUser($user);
        $dependents = $target->database instanceof DatabaseSource ? ServiceInstance::query()
            ->whereKey(Holds::dependentsOf($instance))
            ->with('service')
            ->get()
            ->sortBy(static fn (ServiceInstance $dependent): string => $dependent->service->name)
            ->map(static fn (ServiceInstance $dependent): DependentServiceData => new DependentServiceData($dependent->service->public_id, $dependent->service->name))
            ->values()
            ->all() : [];

        // A copy's own export and import show as the copy (docs/14 §14.2).
        $imports = DataImportRun::query()->where('service_instance_id', $instance->id)
            ->whereNotIn('id', DataCopyRun::query()->select('import_run_id')->whereNotNull('import_run_id'))
            ->latest('id')->limit(5)->get()
            ->map(ImportRuns::view(...))->all();
        $exports = DataExportRun::query()->where('service_instance_id', $instance->id)
            ->whereNotIn('id', DataCopyRun::query()->select('export_run_id')->whereNotNull('export_run_id'))
            ->latest('id')->limit(5)->get()
            ->map(ExportRunData::fromRun(...))->all();
        $copies = DataCopyRun::query()->where('target_service_instance_id', $instance->id)->latest('id')->limit(5)->get()
            ->map(static fn (DataCopyRun $run): CopyRunData => CopyRuns::view($run, $user))->all();

        return new ServiceTransfersData(
            $instance->public_id,
            $instance->service->name,
            $instance->service->slug,
            $target->subject,
            $target->database?->engine->value,
            $target->database?->engine->label(),
            $target->database?->version,
            $target->importSupported(),
            $target->subject instanceof DataSubject ? $target->unavailableReason() : null,
            $target->importSupported() && $gate->allows('create', [DataImportRun::class, $instance]),
            $target->subject instanceof DataSubject && $gate->allows('create', [DataExportRun::class, $instance]),
            BackupDestination::defaultFor($instance->team_id) instanceof BackupDestination,
            array_values($instance->volumes->sortBy('mount_path')->map(static fn (Volume $volume): TransferVolumeData => new TransferVolumeData($volume->public_id, $volume->mount_path))->all()),
            array_values($dependents),
            array_values($imports),
            array_values($exports),
            [
                'self' => route('transfers.services.show', $instance),
                'transfers' => route('transfers.services.transfers.store', $instance),
                'inspect' => route('transfers.services.inspect', $instance),
                'imports' => route('transfers.services.imports.store', $instance),
                'exports' => route('transfers.services.exports.store', $instance),
                'prepare' => route('transfers.services.prepare', $instance),
                'copyOptions' => route('transfers.services.copies.options', $instance),
            ],
            config()->integer('launchroom.transfers.chunk_bytes'),
            config()->integer('launchroom.transfers.max_bytes'),
            $target->subject === DataSubject::Database && $target->importSupported() ? DatabasePreparations::view($instance) : null,
            $target->subject === DataSubject::Database && $target->importSupported() ? ImportFormatData::fromImport($target->import()) : null,
            array_values($copies),
            $target->subject instanceof DataSubject && $gate->allows('copyInto', [DataCopyRun::class, $instance]),
        );
    }
}
