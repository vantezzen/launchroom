<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use Spatie\LaravelData\Data;

/**
 * An environment exported as launchroom.json (docs/18 §4).
 */
final class ConfigExportData extends Data
{
    public function __construct(
        /** Where the project reads the file from (the suggested file name). */
        public string $path,
        /** The file, formatted (two-space indent, sorted keys, trailing newline). */
        public string $content,
        /** The deployed revision it was exported from; null = the current settings (staged changes included). */
        public ?int $revision = null,
    ) {}
}
