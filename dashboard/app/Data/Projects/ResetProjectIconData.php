<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Enums\ProjectIconMode;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;

/**
 * Removing a project's icon: back to the app's favicon, or none at all.
 */
final class ResetProjectIconData extends Data
{
    public function __construct(
        #[In(['automatic', 'none'])]
        public ProjectIconMode $mode,
    ) {}
}
