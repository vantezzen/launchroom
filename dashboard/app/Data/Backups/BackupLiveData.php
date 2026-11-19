<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * How the Backups page stays current: the realtime channel and event it
 * listens to, and a polling interval while something runs (null: no polling).
 */
final class BackupLiveData extends Data
{
    public function __construct(
        /** `backups.<team public id>` (Echo private channel name). */
        public string $channel,
        /** `.backups.changed` */
        public string $event,
        public ?int $pollMs,
    ) {}
}
