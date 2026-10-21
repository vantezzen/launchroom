<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * A toolchain/package version Railpack resolved and where it came from.
 */
final class ResolvedPackage extends Data
{
    public function __construct(
        #[Max(64)]
        public string $name,
        #[Max(128)]
        public string $version,
        #[Max(256)]
        public ?string $source = null,
    ) {}
}
