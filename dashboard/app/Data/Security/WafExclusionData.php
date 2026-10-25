<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * A firewall exception as listed in the service settings (docs/22 §9.6).
 */
final class WafExclusionData extends Data
{
    public function __construct(
        public string $id,
        public int $rule,
        public string $message,
        public ?string $path,
        public ?string $target,
        public ?string $note,
        public ?string $addedBy,
        public string $addedAt,
    ) {}
}
