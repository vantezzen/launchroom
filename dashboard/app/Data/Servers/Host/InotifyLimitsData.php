<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** The host's fs.inotify sysctls. */
final class InotifyLimitsData extends Data
{
    public function __construct(
        public int $maxUserWatches,
        public int $maxUserInstances,
        public int $maxQueuedEvents,
    ) {}
}
