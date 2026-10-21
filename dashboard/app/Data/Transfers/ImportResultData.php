<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use Spatie\LaravelData\Data;

/**
 * What an import left behind (from the task's DataImportResult).
 */
final class ImportResultData extends Data
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $tables,
        public int $files,
        public int $sizeBytes,
        public ?string $serverVersion,
        public ?string $sourceVersion,
        public ?string $format,
        public array $warnings,
        public int $durationMs,
    ) {}

    /**
     * @param  array<string, mixed>|null  $result
     */
    public static function fromResult(?array $result): ?self
    {
        if ($result === null) {
            return null;
        }

        $int = static fn (string $key): int => is_int($result[$key] ?? null) ? $result[$key] : 0;
        $string = static fn (string $key): ?string => is_string($result[$key] ?? null) ? $result[$key] : null;
        $warnings = array_values(array_filter(is_array($result['warnings'] ?? null) ? $result['warnings'] : [], is_string(...)));

        return new self($int('tables'), $int('files'), $int('sizeBytes'), $string('serverVersion'), $string('sourceVersion'), $string('format'), $warnings, $int('durationMs'));
    }
}
