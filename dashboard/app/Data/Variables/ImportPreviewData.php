<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

/**
 * Result of parsing a pasted `.env` (docs/06 §3.4 paste-to-import).
 */
final class ImportPreviewData extends Data
{
    /**
     * @param  list<ImportPreviewEntryData>  $entries
     * @param  list<array{line: int, message: string}>  $errors
     * @param  list<array{line: int, message: string}>  $warnings
     */
    public function __construct(
        public array $entries,
        public array $errors,
        public array $warnings,
    ) {}
}
