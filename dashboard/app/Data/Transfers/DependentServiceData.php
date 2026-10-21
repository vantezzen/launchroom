<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use Spatie\LaravelData\Data;

/**
 * A service that stops while its database is imported into.
 */
final class DependentServiceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
