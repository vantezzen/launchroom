<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Data\Protocol\V1\DataImportResult;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

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

    public static function fromResult(?DataImportResult $result): ?self
    {
        if (! $result instanceof DataImportResult) {
            return null;
        }

        $int = static fn (int|Optional $value): int => $value instanceof Optional ? 0 : $value;
        $string = static fn (string|Optional $value): ?string => $value instanceof Optional ? null : $value;
        $warnings = $result->warnings instanceof Optional ? [] : array_values(array_filter($result->warnings ?? [], is_string(...)));

        return new self($int($result->tables), $int($result->files), $int($result->sizeBytes), $string($result->serverVersion), $string($result->sourceVersion), $string($result->format), $warnings, $result->durationMs);
    }
}
