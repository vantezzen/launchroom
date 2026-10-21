<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use App\Enums\DatabaseEngine;
use Spatie\LaravelData\Data;

/**
 * The choices a preview offers for one Compose service (docs/17 §2.1): a
 * well-known database image as a managed database or a plain container,
 * and how many directory bind mounts can become volumes.
 */
final class ServiceChoiceData extends Data
{
    public function __construct(
        /** The Compose service name the choice is keyed by. */
        public string $service,
        public ?DatabaseEngine $engine = null,
        public ?bool $managed = null,
        public int $bindMounts = 0,
    ) {}
}
