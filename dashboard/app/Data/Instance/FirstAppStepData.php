<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * One row of the first deploy's step list (Build, Start, Health check,
 * Route) on the first-live card (docs/08 §3.1).
 */
final class FirstAppStepData extends Data
{
    public function __construct(
        public string $label,
        #[LiteralTypeScriptType("'pending' | 'running' | 'succeeded' | 'failed' | 'skipped'")]
        public string $status,
        public ?CarbonInterface $startedAt = null,
        public ?CarbonInterface $finishedAt = null,
    ) {}
}
