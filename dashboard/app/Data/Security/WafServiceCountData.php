<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * A service in "Most targeted" or "Watching, not blocking yet" (docs/22 §9.2).
 */
final class WafServiceCountData extends Data
{
    /**
     * @param  list<string>  $categories  top two category labels
     */
    public function __construct(
        public SecurityServiceRefData $service,
        public int $blocked,
        public int $detected,
        public array $categories,
        public ?string $topPath,
        /** POST target of "Turn on blocking" (Log only services). */
        public ?string $settingsUrl,
    ) {}
}
