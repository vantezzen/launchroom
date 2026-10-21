<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Data\Services\DatabaseSource;
use App\Data\Transfers\DependentServiceData;
use App\Data\Transfers\ExportRunData;
use App\Data\Transfers\ImportRunData;
use App\Data\Transfers\ServiceTransfersData;
use App\Data\Transfers\TransferVolumeData;
use App\Enums\Permission;
use App\Enums\Transfers\DataSubject;
use App\Models\Backups\BackupDestination;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backups\RestoreHolds;

/**
 * Builds the Move data section's data (docs/14 §12.6).
 */
final class ServiceTransfersView
{
    public function for(ServiceInstance $instance, User $user): ServiceTransfersData
    {
        $instance->loadMissing(['service', 'volumes']);
        $target = ImportTarget::for($instance);
        $manage = $user->hasTeamPermission($instance->team_id, Permission::ManageBackups);
        $dependents = $target->database instanceof DatabaseSource ? ServiceInstance::query()
            ->whereKey(RestoreHolds::dependentIds($instance))
            ->with('service')
            ->get()
            ->map(static fn (ServiceInstance $dependent): DependentServiceData => new DependentServiceData($dependent->service->public_id, $dependent->service->name))
            ->all() : [];

        $imports = DataImportRun::query()->where('service_instance_id', $instance->id)->latest('id')->limit(5)->get()
            ->map(ImportRunData::fromRun(...))->all();
        $exports = DataExportRun::query()->where('service_instance_id', $instance->id)->latest('id')->limit(5)->get()
            ->map(ExportRunData::fromRun(...))->all();

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
            $manage && $target->importSupported(),
            $manage && $target->subject instanceof DataSubject,
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
            ],
            config()->integer('launchroom.transfers.chunk_bytes'),
            config()->integer('launchroom.transfers.max_bytes'),
        );
    }
}
