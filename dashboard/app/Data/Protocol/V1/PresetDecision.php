<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * One removable decision a preset made (a UI chip); `id` is what
 * `builder.disabledDecisions` references.
 */
final class PresetDecision extends Data
{
    public function __construct(
        #[Max(64)]
        public string $id,
        #[Max(256)]
        public string $title,
        #[Max(1024)]
        public ?string $detail = null,
    ) {}
}
