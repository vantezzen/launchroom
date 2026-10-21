<?php

declare(strict_types=1);

namespace App\Data\Backups;

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
    ) {}

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
        );
    }
}
