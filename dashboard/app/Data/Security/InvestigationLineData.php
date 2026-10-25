<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One sentence of "What we found" (docs/22 §7.5). `network` is the client
 * network a firewall line is about; only owners and admins get it.
 */
final class InvestigationLineData extends Data
{
    public function __construct(
        /** `concerning`, `notable` or `clear`. */
        public string $level,
        public string $text,
        public ?string $network = null,
    ) {}
}
