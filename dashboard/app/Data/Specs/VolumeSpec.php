<?php

declare(strict_types=1);

namespace App\Data\Specs;

use Spatie\LaravelData\Data;

/**
 * A volume a service mounts, keyed by volume id in ServiceSpec::$volumes.
 * `sharedFrom` is set for a volume of another service of the environment
 * (the owner's service id, D73): this service mounts it at its own path,
 * the volume itself belongs to the owner.
 */
final class VolumeSpec extends Data
{
    public function __construct(
        public string $id,
        public string $mountPath,
        public ?string $sharedFrom = null,
    ) {}
}
