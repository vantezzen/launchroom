<?php

declare(strict_types=1);

namespace App\Data\Variables;

use App\Enums\VariableType;
use Spatie\LaravelData\Data;

/**
 * One key of a pasted `.env` before importing (no value: the client has the
 * text it pasted). `status`: `new` (created), `update` (replaces the value of
 * the variable defined for exactly this environment), `conflict` (the key is
 * defined for this environment together with others; edit it in the table).
 */
final class ImportPreviewEntryData extends Data
{
    public function __construct(
        public string $key,
        public int $line,
        public VariableType $suggestedType,
        public bool $browserInlined,
        public string $status,
        public bool $existingSecret,
    ) {}
}
