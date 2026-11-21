<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use Spatie\LaravelData\Data;

/**
 * The inspections a copy's review starts (docs/14 §14.3): the target's, and
 * the source's for a fresh copy of a database. Poll each by its id.
 */
final class CopyInspectionData extends Data
{
    public function __construct(
        public ImportInspectionData $target,
        public ?ImportInspectionData $source = null,
    ) {}
}
