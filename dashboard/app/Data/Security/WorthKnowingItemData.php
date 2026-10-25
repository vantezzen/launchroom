<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * A row of a "Worth knowing" group (docs/22 §9.2 part 4): one package
 * version and advisory across the services that run it.
 */
final class WorthKnowingItemData extends Data
{
    /**
     * @param  list<SecurityServiceRefData>  $services
     */
    public function __construct(
        public string $key,
        public string $package,
        public string $version,
        public string $ecosystem,
        public ?string $advisory,
        public ?string $title,
        public string $severity,
        public ?string $fixedVersion,
        public ?string $href,
        public array $services,
        public string $reason,
        public string $text,
    ) {}
}
