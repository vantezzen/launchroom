<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * One variable a connection injects: a key and its reference template
 * (`${{postgres.PGPASSWORD}}`), never a resolved value.
 */
final class ConnectionVariableData extends Data
{
    public function __construct(
        public string $key,
        public string $value,
    ) {}
}
