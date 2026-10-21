<?php

declare(strict_types=1);

namespace App\Data\Git;

use Spatie\LaravelData\Data;

/**
 * Result of recording a ref's head: the previous head (null when the ref was
 * unknown) and whether it changed.
 */
final class RefHeadUpdate extends Data
{
    public function __construct(
        public ?string $previous,
        public bool $changed,
    ) {}
}
