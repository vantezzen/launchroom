<?php

declare(strict_types=1);

namespace App\Data\Transfers\Inputs;

use App\Data\Attributes\PublicIdOf;
use Spatie\LaravelData\Data;

/**
 * POST …/copies/inspect (docs/14 §14.3): look at the target (does it hold
 * data) and, for a fresh copy of a database, at the source (its size).
 */
final class InspectCopyInput extends Data
{
    public function __construct(
        #[PublicIdOf('env')]
        public ?string $source = null,
        #[PublicIdOf('vol')]
        public ?string $volume = null,
    ) {}
}
