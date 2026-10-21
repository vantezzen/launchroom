<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Spatie\LaravelData\Data;

/**
 * "How launchroom updates" on Settings → Updates: the channel, automatic
 * updates and their night window in the control-plane server's time zone
 * (`windowStart`/`windowEnd` hours, `timezone` an IANA name or UTC).
 * `canAutoUpdate`: a control-plane backup destination exists.
 */
final class UpdatePreferencesData extends Data
{
    public function __construct(
        public string $channel,
        public bool $autoUpdate,
        public bool $canAutoUpdate,
        public int $windowStart,
        public int $windowEnd,
        public string $timezone,
    ) {}
}
