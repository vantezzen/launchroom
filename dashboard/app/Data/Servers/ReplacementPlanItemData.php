<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One service that lived on the dead server (docs/14 §5.3): stateful ones
 * can be restored from their latest backup ("restore from latest backup
 * (age N h)"), stateless ones are re-placed.
 */
final class ReplacementPlanItemData extends Data
{
    public function __construct(
        public string $instanceId,
        public string $name,
        public bool $stateful,
        public ?string $snapshot,
        public ?CarbonInterface $backupTakenAt,
        /** Why it can't be restored (no backup yet), if so. */
        public ?string $reason,
    ) {}
}
