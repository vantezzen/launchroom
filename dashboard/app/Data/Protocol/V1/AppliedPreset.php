<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * A launchroom preset that matched and the decisions it applied.
 */
final class AppliedPreset extends Data
{
    /**
     * @param  list<PresetDecision>  $decisions
     */
    public function __construct(
        #[Max(64)]
        public string $name,
        #[Min(1)]
        public int $version,
        public array $decisions = [],
    ) {}
}
