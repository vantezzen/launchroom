<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\NotBackedUpReason;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Something that holds data but isn't backed up, and why (the Backups
 * page's "Not backed up" group).
 */
final class NotBackedUpItemData extends Data
{
    public function __construct(
        /** `volume:<vol_…>`, `database:<sin_…>`, `service:<sin_…>` or `environment:<env_…>` (previews). */
        public string $id,
        #[LiteralTypeScriptType("'database' | 'volume' | 'service' | 'environment'")]
        public string $kind,
        public string $name,
        public ?string $serviceName,
        public ?string $projectName,
        public ?string $environmentName,
        public NotBackedUpReason $reason,
        public ?string $turnedOffBy,
        public ?CarbonInterface $turnedOffAt,
        /** volume_off: PUT {enabled: true}; schedule_off: the schedule's update URL (send it with enabled: true). */
        public ?BackupActionData $turnOn,
        /** schedule_off: the schedule to send back with enabled: true. */
        public ?BackupScheduleData $schedule = null,
        /** The service's Backups tab. */
        public ?string $href = null,
    ) {}
}
