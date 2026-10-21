<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

/**
 * One target of a variable as shown in the editor: an environment, or all
 * previews (optionally for a branch pattern).
 */
final class VariableTargetChipData extends Data
{
    public function __construct(
        public ?string $environmentId,
        public string $label,
        public bool $allPreviews,
        public ?string $branchPattern,
    ) {}
}
