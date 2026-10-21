<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use Spatie\LaravelData\Data;

/**
 * "Also promote images" for one service (docs/06 §6.4): the source's active
 * build and whether the target would reuse it as is (equal build inputs) or
 * rebuild the same commit with its own build-scoped variables.
 */
final class PromotionImageData extends Data
{
    /**
     * @param  list<string>  $differences  build inputs that differ (variable keys, settings)
     */
    public function __construct(
        public string $serviceId,
        public string $name,
        public string $deploymentId,
        public ?string $commitSha,
        public bool $sameInputs,
        public array $differences,
    ) {}
}
