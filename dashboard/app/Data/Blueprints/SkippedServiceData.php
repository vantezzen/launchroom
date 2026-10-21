<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use Spatie\LaravelData\Data;

/**
 * A Compose service that doesn't become a launchroom service, and why.
 */
final class SkippedServiceData extends Data
{
    public function __construct(
        public string $name,
        public string $reason,
        public ?int $line = null,
        /** True when importing from a repository would let it build. */
        public bool $needsRepository = false,
    ) {}
}
