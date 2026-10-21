<?php

declare(strict_types=1);

namespace App\Data\Previews;

use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Approving a fork's preview names the head commit the maintainer saw
 * (docs/09 R20): if the fork pushed since, the approval is refused.
 */
final class ApprovePreviewInput extends Data
{
    public function __construct(
        #[Regex('/^[0-9a-f]{40}([0-9a-f]{24})?$/D')]
        public string $headSha,
    ) {}
}
