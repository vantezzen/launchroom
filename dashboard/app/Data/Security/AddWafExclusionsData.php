<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * "Allow requests like this" (docs/22 §6.4): the exceptions for every rule
 * that scored on a blocked request, added together. An event carries at
 * most 8 rules.
 */
final class AddWafExclusionsData extends Data
{
    /**
     * @param  list<AddWafExclusionData>  $exclusions
     */
    public function __construct(
        #[DataCollectionOf(AddWafExclusionData::class), Min(1), Max(8)]
        public array $exclusions,
    ) {}
}
