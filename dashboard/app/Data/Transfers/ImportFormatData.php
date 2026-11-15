<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Drivers\DatabaseEngines\DumpImport;
use Spatie\LaravelData\Data;

/**
 * What the import sheet tells about a database's import: the dump files it
 * takes, an example source URL and what an inspection counts.
 */
final class ImportFormatData extends Data
{
    public function __construct(
        public string $formats,
        public string $urlExample,
        /** `tables` or `collections`. */
        public string $objects,
    ) {}

    public static function fromImport(DumpImport $import): self
    {
        return new self($import->formatsHint, $import->urlExample, $import->objects);
    }
}
