<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * A project in the top bar's project switcher.
 */
final class ProjectLinkData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        /** The project icon (docs/08 §13); null → monogram. */
        public ?string $iconUrl = null,
    ) {}
}
