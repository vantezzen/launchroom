<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\DestinationErrorKind;
use App\Enums\Backups\DestinationScope;
use App\Enums\Backups\DestinationStatus;
use App\Models\Backups\BackupDestination;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A destination as shown in Settings → Backups. Whitelisted fields only:
 * never credentials or the repository password (docs/09 R12).
 */
final class BackupDestinationData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public DestinationScope $scope,
        public string $endpoint,
        public string $bucket,
        public ?string $region,
        public string $prefix,
        public bool $allowPrivateNetwork,
        public bool $isDefault,
        public DestinationStatus $status,
        public ?CarbonInterface $lastCheckedAt,
        public ?string $lastError,
        public int $scheduleCount,
        public bool $canManage,
        /** Failing only: what to fix instead of "Unreachable" for everything. */
        public ?DestinationErrorKind $errorKind = null,
        public ?DestinationUsageData $usage = null,
        /** PUT BackupDestinationInput; null without permission. */
        public ?string $updateUrl = null,
        public ?string $testUrl = null,
        public ?string $removeUrl = null,
        /** For the removal confirmation: schedules that move or wait, runs whose history is kept. */
        public int $runCount = 0,
        /** launchroom's own storage was copied from this one (same bucket, folder launchroom/). */
        public bool $sharedWithLaunchroom = false,
        /** POST: the repository password for plain restic (audited); null without the permission. */
        public ?string $repositoryPasswordUrl = null,
    ) {}

    /**
     * The Backups page's card: classified error, usage, action URLs.
     */
    public static function forPage(BackupDestination $destination, int $scheduleCount, bool $canManage, ?DestinationUsageData $usage, int $runCount, bool $sharedWithLaunchroom, bool $canRevealPassword = false): self
    {
        $data = self::fromDestination($destination, $scheduleCount, $canManage);
        $data->usage = $usage;
        $data->runCount = $runCount;
        $data->sharedWithLaunchroom = $sharedWithLaunchroom;

        if ($canRevealPassword) {
            $data->repositoryPasswordUrl = route('backups.destinations.repository-password', $destination->public_id);
        }

        if ($canManage) {
            $data->updateUrl = route('backups.destinations.update', $destination->public_id);
            $data->testUrl = route('backups.destinations.test', $destination->public_id);
            $data->removeUrl = route('backups.destinations.destroy', $destination->public_id);
        }

        return $data;
    }

    public static function fromDestination(BackupDestination $destination, int $scheduleCount, bool $canManage): self
    {
        return new self(
            $destination->public_id,
            $destination->name,
            $destination->team_id === null ? DestinationScope::Instance : DestinationScope::Team,
            $destination->endpoint,
            $destination->bucket,
            $destination->region,
            $destination->prefix,
            $destination->allow_private_network,
            $destination->is_default,
            $destination->status,
            $destination->last_checked_at,
            $destination->last_error,
            $scheduleCount,
            $canManage,
            $destination->status === DestinationStatus::Failing ? ($destination->error_kind ?? DestinationErrorKind::classify($destination->last_error) ?? DestinationErrorKind::Other) : null,
        );
    }
}
