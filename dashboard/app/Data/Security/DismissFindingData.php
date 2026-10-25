<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Enums\Security\DismissReason;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * "Not affected…" (docs/22 §5.5).
 */
final class DismissFindingData extends Data
{
    public function __construct(
        public DismissReason $reason,
        #[Max(500)]
        public ?string $note = null,
    ) {}
}
