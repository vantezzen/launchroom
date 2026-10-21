<?php

declare(strict_types=1);

namespace App\Data\StagedChanges;

use Spatie\LaravelData\Data;

/**
 * A recent revision of an environment (Revert to revision N, docs/06 §6.5).
 */
final class RevisionData extends Data
{
    public function __construct(
        public string $id,
        public int $number,
        public ?string $message,
        public string $createdAt,
        public ?string $author,
        /** The last applied revision (what is live). */
        public bool $current,
    ) {}
}
