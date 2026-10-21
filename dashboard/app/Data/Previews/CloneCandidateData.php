<?php

declare(strict_types=1);

namespace App\Data\Previews;

use Spatie\LaravelData\Data;

/**
 * A database "clone from another database" may copy (docs/14 §6), with the
 * size of its latest backup and whether the size guard / production toggle
 * allow it.
 */
final class CloneCandidateData extends Data
{
    public function __construct(
        public string $id,
        public string $service,
        public string $environment,
        public bool $production,
        public ?int $sizeBytes,
        public ?string $backedUpAt,
        public bool $allowed,
        public ?string $reason,
    ) {}
}
