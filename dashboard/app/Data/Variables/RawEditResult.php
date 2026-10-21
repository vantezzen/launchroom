<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

/**
 * Result of saving the raw `.env` editor: keys created, changed and removed
 * in this environment, plus parser warnings.
 */
final class RawEditResult extends Data
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $updated
     * @param  list<string>  $removed
     * @param  list<array{line: int, message: string}>  $warnings
     */
    public function __construct(
        public array $created = [],
        public array $updated = [],
        public array $removed = [],
        public array $warnings = [],
    ) {}
}
