<?php

declare(strict_types=1);

namespace App\Data\CommandPalette;

use Spatie\LaravelData\Data;

/**
 * Something ⌘K can jump to (docs/08 §4): a project, environment, service,
 * deployment or server, with the ids the client needs to build its URLs.
 */
final class PaletteItemData extends Data
{
    public function __construct(
        public string $kind,
        public string $id,
        public string $label,
        public ?string $detail = null,
        public ?string $projectId = null,
        public ?string $environmentId = null,
        public ?string $status = null,
        /** Projects: the project icon (docs/08 §13). */
        public ?string $iconUrl = null,
    ) {}
}
