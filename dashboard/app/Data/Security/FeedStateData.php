<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One advisory source's state (docs/22 §4.4, §9.8).
 */
final class FeedStateData extends Data
{
    public function __construct(
        /** `osv`, `kev` or `eol`. */
        public string $feed,
        public string $label,
        public string $host,
        public bool $enabled,
        public ?string $lastSuccessAt,
        public ?string $failingSince,
        public ?string $lastError,
        public int $items,
    ) {}
}
