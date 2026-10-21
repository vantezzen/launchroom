<?php

declare(strict_types=1);

namespace App\Data\Variables;

/**
 * Result of App\Support\Variables\EnvFileParser.
 */
final readonly class EnvFileResult
{
    /**
     * @param  list<EnvFileEntry>  $entries  in file order; for duplicate keys the last one wins
     * @param  list<array{line: int, message: string}>  $errors
     * @param  list<array{line: int, message: string}>  $warnings
     */
    public function __construct(
        public array $entries,
        public array $errors = [],
        public array $warnings = [],
    ) {}

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (EnvFileEntry $entry): string => $entry->key, $this->entries);
    }
}
