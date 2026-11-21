<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Enums\Transfers\CopyStep;
use App\Enums\Transfers\CopyStepState;
use Spatie\LaravelData\Data;

/**
 * One step of a copy as the UI shows it (docs/14 §14.2).
 */
final class CopyStepData extends Data
{
    public function __construct(
        public CopyStep $key,
        public string $label,
        public CopyStepState $state,
        public ?string $detail = null,
    ) {}
}
