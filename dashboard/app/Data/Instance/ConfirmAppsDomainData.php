<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * "Continue" (`anyway` false: only once DNS checks out) or "Continue anyway"
 * on the address step.
 */
final class ConfirmAppsDomainData extends Data
{
    public function __construct(
        public bool $anyway = false,
    ) {}
}
