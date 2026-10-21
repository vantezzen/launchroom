<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\VolumeRetirementStatus;
use App\Models\VolumeRetirement;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A volume of a deleted environment, project or preview on the server page
 * (docs/05 §1.1): kept until `retainUntil`, then deleted on request.
 */
final class RetiredVolumeData extends Data
{
    public function __construct(
        public string $id,
        public string $label,
        public string $name,
        public string $mountPath,
        public string $reason,
        public VolumeRetirementStatus $status,
        public CarbonInterface $retainUntil,
        public bool $needsConfirmation,
        public ?CarbonInterface $confirmedAt,
        public ?CarbonInterface $requestedAt,
        public ?CarbonInterface $deletedAt,
        public ?string $message,
    ) {}

    public static function fromModel(VolumeRetirement $retirement): self
    {
        return new self(
            $retirement->volume_public_id,
            $retirement->label,
            $retirement->docker_name,
            $retirement->mount_path,
            $retirement->reason,
            $retirement->status,
            $retirement->retain_until,
            $retirement->backups_configured && $retirement->confirmed_at === null,
            $retirement->confirmed_at,
            $retirement->requested_at,
            $retirement->deleted_at,
            $retirement->message,
        );
    }
}
