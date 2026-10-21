<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

final class ImportResult extends Data
{
    /**
     * @param  list<string>  $created  keys
     * @param  list<string>  $updated  keys
     * @param  list<array{line: int, message: string}>  $warnings
     */
    public function __construct(
        public array $created = [],
        public array $updated = [],
        public array $warnings = [],
    ) {}
}
