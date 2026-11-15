<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use Spatie\LaravelData\Data;

/**
 * A commit "Also promote images" pinned for one service: the source's active
 * deployment and what it was built from (`environment_promotions.images`).
 */
final class PinnedImageData extends Data
{
    public function __construct(
        public string $deployment,
        public string $commitSha,
        public ?string $gitRef = null,
    ) {}
}
