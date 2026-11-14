<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * The deployment whose image a finding was found in (docs/22 §9.3).
 */
final class FindingDeploymentData extends Data
{
    public function __construct(
        public string $id,
        /** Short commit SHA, or null for an image without a commit. */
        public ?string $sha,
        public string $href,
    ) {}
}
