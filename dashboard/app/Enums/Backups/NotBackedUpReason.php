<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Why something that holds data isn't backed up (the Backups page's
 * "Not backed up" group).
 */
enum NotBackedUpReason: string
{
    /** "Back up this volume" was turned off. */
    case VolumeOff = 'volume_off';
    /** The service's automatic backups were turned off. */
    case ScheduleOff = 'schedule_off';
    /** Previews are never backed up (docs/14 §3). */
    case Preview = 'preview';
    /** Not scheduled yet (added since the last scheduler tick). */
    case NoSchedule = 'no_schedule';
}
