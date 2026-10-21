<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\DeviceKind;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * PATCH /servers/{server}/appearance: "Looks like" and "Location"
 * (docs/design/direction.md §7.3). Only the keys present change. `device`
 * null goes back to the detected device; `location` null forgets the
 * stored location (suggestions come back). Confirming a suggestion is
 * sending it as `location`.
 */
final class UpdateServerAppearanceData extends Data
{
    public function __construct(
        public DeviceKind|null|Optional $device = new Optional,
        public ServerLocationInputData|null|Optional $location = new Optional,
    ) {}
}
