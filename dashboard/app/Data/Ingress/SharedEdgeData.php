<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use Spatie\LaravelData\Data;

/**
 * Admit an edge to the shared certificates and DNS-01 credentials, or
 * remove it.
 */
final class SharedEdgeData extends Data
{
    public function __construct(
        public bool $shared,
    ) {}
}
