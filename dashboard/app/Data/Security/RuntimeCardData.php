<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * A runtime, engine or base image in use (docs/22 §9.2 part 5) with its
 * release cycle from endoflife.date.
 */
final class RuntimeCardData extends Data
{
    /**
     * @param  list<string>  $packageVersions
     */
    public function __construct(
        public string $key,
        public string $product,
        public string $label,
        public ?string $logo,
        public string $version,
        public ?string $cycle,
        public int $services,
        public ?string $releaseDate,
        public ?string $eol,
        public bool $isEol,
        public ?string $latest,
        /** A newer patch of the cycle, out for more than 30 days. */
        public bool $patchAvailable,
        /** The package versions as the images carry them, when they differ from `version` ("1.31.8-1~trixie"). */
        public array $packageVersions = [],
    ) {}
}
