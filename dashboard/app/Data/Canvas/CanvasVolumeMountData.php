<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * One service mounting a canvas volume, at its own path (D73).
 */
final class CanvasVolumeMountData extends Data
{
    public function __construct(
        public string $serviceId,
        public string $mountPath,
        /** The volume's own service (not detachable; removing the volume goes through its settings). */
        public bool $owner,
        /** Added or changed since the last deploy. */
        public bool $staged = false,
        /** Detached: the running deployment still mounts it until the next deploy. */
        public bool $removing = false,
    ) {}
}
