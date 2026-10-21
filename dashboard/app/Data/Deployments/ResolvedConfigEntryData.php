<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * One setting of the configuration a deployment was made from (its
 * revision), flattened for display. Settings never hold secrets.
 */
final class ResolvedConfigEntryData extends Data
{
    public function __construct(
        public string $group,
        public string $key,
        public string $value,
    ) {}
}
