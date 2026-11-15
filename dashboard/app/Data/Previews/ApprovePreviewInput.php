<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Approving a fork's preview names the head commit the maintainer saw
 * (docs/09 R20): if the fork pushed since, the approval is refused.
 */
final class ApprovePreviewInput extends Data
{
    public function __construct(
        #[Regex(Grammar::GIT_SHA)]
        public string $headSha,
    ) {}
}
