<?php

declare(strict_types=1);

namespace App\Queries\Transfers;

use App\Data\Services\DatabaseSource;
use App\Data\Transfers\CopyBackupData;
use App\Data\Transfers\CopyOptionsData;
use App\Data\Transfers\CopySourceData;
use App\Data\Transfers\DependentServiceData;
use App\Data\Transfers\TransferVolumeData;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\EnvironmentKind;
use App\Enums\Transfers\DataSubject;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataCopyRun;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Holds\Holds;
use App\Services\Transfers\CopyRules;
use App\Services\Transfers\ImportTarget;
use Illuminate\Support\Facades\Gate;

/**
 * The copy sheet's data for one target (docs/14 §14.1): every other
 * environment of the project with the same service there, its backups and
 * why it can't be the source.
 */
final class CopyOptions
{
    /** Backups listed per source. */
    private const int BACKUPS = 20;

    public function for(ServiceInstance $target, User $user): CopyOptionsData
    {
        $target->loadMissing(['service', 'environment.project', 'volumes']);
        $subject = ImportTarget::for($target);
        $gate = Gate::forUser($user);

        $environments = Environment::query()
            ->where('project_id', $target->environment->project_id)
            ->whereKeyNot($target->environment_id)
            ->get()
            ->sortBy(static fn (Environment $environment): string => $environment->kind->sortOrder().$environment->name)
            ->values();
        $instances = ServiceInstance::query()
            ->current()
            ->where('service_id', $target->service_id)
            ->whereIn('environment_id', $environments->modelKeys())
            ->with(['service', 'environment', 'volumes'])
            ->get()
            ->keyBy('environment_id');

        $sources = [];

        foreach ($environments as $environment) {
            $source = $instances->get($environment->id);
            $sources[] = $source instanceof ServiceInstance
                ? $this->source($source, $target, $subject->subject ?? DataSubject::Database, $gate->allows('create', [DataCopyRun::class, $source, $target]))
                : new CopySourceData($environment->public_id, $environment->name, $environment->kind, null, [], [], "{$target->service->name} isn’t in {$environment->name}.", false);
        }

        $dependents = $subject->subject === DataSubject::Database
            ? ServiceInstance::query()->whereKey(Holds::dependentsOf($target))->with('service')->get()
                ->sortBy(static fn (ServiceInstance $dependent): string => $dependent->service->name)
                ->map(static fn (ServiceInstance $dependent): DependentServiceData => new DependentServiceData($dependent->service->public_id, $dependent->service->name))
                ->values()->all()
            : [];

        return new CopyOptionsData(
            serviceName: $target->service->name,
            subject: $subject->subject ?? DataSubject::Database,
            versionLabel: $this->versionLabel(DatabaseWorkloads::source($target)),
            environmentName: $target->environment->name,
            environmentKind: $target->environment->kind,
            refusal: $subject->subject instanceof DataSubject ? CopyRules::targetRefusal($target) : 'Only databases and services with volumes hold data to copy.',
            hasDestination: BackupDestination::forService($target) instanceof BackupDestination,
            sources: $sources,
            dependents: array_values($dependents),
            volumes: array_values($target->volumes->sortBy('mount_path')->map(static fn (Volume $volume): TransferVolumeData => new TransferVolumeData($volume->public_id, $volume->mount_path))->all()),
            urls: [
                'inspect' => route('transfers.services.copies.inspect', $target),
                'copies' => route('transfers.services.copies.store', $target),
            ],
            stopEstimate: ['baseSeconds' => CopyRules::STOP_BASE_SECONDS, 'bytesPerMinute' => CopyRules::BYTES_PER_MINUTE],
        );
    }

    private function source(ServiceInstance $source, ServiceInstance $target, DataSubject $subject, bool $allowed): CopySourceData
    {
        $environment = $source->environment;
        $refusal = CopyRules::sourceRefusal($source, $target);

        if ($refusal === null && ! $allowed) {
            $refusal = $environment->kind === EnvironmentKind::Production || $target->environment->kind === EnvironmentKind::Production
                ? 'Copying production data needs an owner or admin.'
                : 'Copying data needs the permission to manage projects and deploy.';
        }

        $destination = BackupDestination::forService($source);
        $backups = $destination instanceof BackupDestination ? BackupRun::query()
            ->where('service_instance_id', $source->id)
            ->where('destination_id', $destination->id)
            ->where('subject', $subject === DataSubject::Volume ? BackupSubject::Volume->value : BackupSubject::Database->value)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->whereNotNull('snapshot_id')
            ->with('volume')
            ->latest('finished_at')
            ->latest('id')
            ->limit($subject === DataSubject::Volume ? self::BACKUPS * max(1, $source->volumes->count()) : self::BACKUPS)
            ->get()
            ->map(static fn (BackupRun $run): CopyBackupData => new CopyBackupData((string) $run->snapshot_id, ($run->finished_at ?? $run->created_at)?->toIso8601String() ?? '', $run->size_bytes, $run->volume?->mount_path))
            ->all() : [];

        return new CopySourceData(
            $environment->public_id,
            $environment->name,
            $environment->kind,
            $this->versionLabel(DatabaseWorkloads::source($source)),
            array_values($backups),
            array_values($source->volumes->map(static fn (Volume $volume): string => $volume->mount_path)->sort()->all()),
            $refusal,
            $destination instanceof BackupDestination,
        );
    }

    private function versionLabel(?DatabaseSource $database): ?string
    {
        return $database instanceof DatabaseSource ? "{$database->engine->label()} {$database->version}" : null;
    }
}
