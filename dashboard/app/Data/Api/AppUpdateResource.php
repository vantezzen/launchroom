<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Data\AppUpdates\AppUpdateRunData;
use App\Data\AppUpdates\ServiceUpdateData;
use App\Data\AppUpdates\UpdateCandidateData;
use App\Enums\AppUpdates\AutoUpdateMode;
use App\Enums\AppUpdates\UpdateCheckStatus;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * Updates of one image service in one environment (D106, docs/04 §14): what
 * runs, the newer releases launchroom found (`candidates`, biggest change
 * first) and the update running now or that ended within a day (`run`).
 * Apply one with `POST /environments/{environment}/services/{service}/update`
 * and a candidate's `tag`; a `major` needs `releaseNotesRead`.
 */
final class AppUpdateResource extends Data
{
    /**
     * @param  list<UpdateCandidateData>  $candidates
     * @param  list<string>  $backups  what an update backs up first
     */
    public function __construct(
        public string $serviceId,
        public string $serviceName,
        public string $projectId,
        public string $projectName,
        public string $environmentId,
        public string $environmentName,
        public bool $production,
        public UpdateCheckStatus $status,
        public ?string $reason,
        /** `v6.2.0`, or the tag when it isn't a version. */
        public ?string $running,
        /** The running tag moves (`latest`, `6`). */
        public bool $moving,
        public ?string $runningPublishedAt,
        public ?string $image,
        #[DataCollectionOf(UpdateCandidateData::class)]
        public array $candidates,
        /** The candidate the dashboard offers first: the newest patch or minor. */
        public ?UpdateCandidateData $recommended,
        public ?UpdateCandidateData $major,
        public ?string $releaseNotesUrl,
        public ?string $checkedAt,
        public ?AppUpdateRunData $run,
        /** This token may start an update (change the service and deploy). */
        public bool $canUpdate,
        /** Why an update can't start right now (staged changes, a deployment in progress…). */
        public ?string $blocker,
        public array $backups,
        public bool $keepsData,
        public bool $backupStorage,
        public AutoUpdateMode $autoUpdate,
        public string $dashboardUrl,
    ) {}

    public static function fromUpdate(ServiceUpdateData $update, string $dashboardUrl): self
    {
        return new self(
            serviceId: $update->serviceId,
            serviceName: $update->serviceName,
            projectId: $update->projectId,
            projectName: $update->projectName,
            environmentId: $update->environmentId,
            environmentName: $update->environmentName,
            production: $update->production,
            status: $update->status,
            reason: $update->reason,
            running: $update->running,
            moving: $update->moving,
            runningPublishedAt: $update->runningPublishedAt,
            image: $update->image,
            candidates: $update->candidates,
            recommended: $update->recommended,
            major: $update->major,
            releaseNotesUrl: $update->releaseNotesUrl,
            checkedAt: $update->checkedAt,
            run: $update->run,
            canUpdate: $update->canUpdate,
            blocker: $update->blocker,
            backups: $update->backups,
            keepsData: $update->keepsData,
            backupStorage: $update->backupStorage,
            autoUpdate: $update->autoUpdate,
            dashboardUrl: $dashboardUrl,
        );
    }
}
