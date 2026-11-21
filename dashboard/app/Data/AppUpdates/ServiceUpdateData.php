<?php

declare(strict_types=1);

namespace App\Data\AppUpdates;

use App\Enums\AppUpdates\AutoUpdateMode;
use App\Enums\AppUpdates\UpdateCheckStatus;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * Updates of one image service instance (docs/04 §14.4): the service
 * panel's row, Settings → Source → Updates, the update dialog and the
 * Security page's App updates list.
 */
final class ServiceUpdateData extends Data
{
    /**
     * @param  list<UpdateCandidateData>  $candidates  biggest change first
     * @param  list<string>  $backups  what an update backs up first
     */
    public function __construct(
        public string $serviceId,
        public string $serviceName,
        public string $logo,
        public string $projectId,
        public string $projectName,
        public string $environmentId,
        public string $environmentName,
        public bool $production,
        public UpdateCheckStatus $status,
        public ?string $reason,
        /** `v6.2.0`, or the tag when it isn't a version. */
        public ?string $running,
        /** The running tag moves (`latest`, `6`): the version behind it is only known by its age. */
        public bool $moving,
        /** A major is out, or the running version is more than a year old: shown first and marked. */
        public bool $standsOut,
        public ?string $runningPublishedAt,
        public ?string $image,
        #[DataCollectionOf(UpdateCandidateData::class)]
        public array $candidates,
        public ?UpdateCandidateData $recommended,
        public ?UpdateCandidateData $major,
        public ?string $releaseNotesUrl,
        public ?string $checkedAt,
        /** The update running now, or the last one when it ended within a day. */
        public ?AppUpdateRunData $run,
        /** launchroom checks it (it runs, in a lasting environment): Check now works. */
        public bool $checkable,
        /** May start an update (change the service and deploy). */
        public bool $canUpdate,
        /** Why an update can't start right now. */
        public ?string $blocker,
        public array $backups,
        /** It has data an update backs up first (connected databases, its own volumes). */
        public bool $keepsData,
        /** Backup storage is set up (asked only when it keeps data). */
        public bool $backupStorage,
        public int $watchMinutes,
        public AutoUpdateMode $autoUpdate,
        public ?UpdateWindowData $window,
        /** Why automatic updates can't be turned on ("Set up backup storage first"). */
        public ?string $autoBlocker,
    ) {}
}
