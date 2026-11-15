<?php

declare(strict_types=1);

namespace App\Data\Attributes;

use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\RuleInferrers\RuleInferrer;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Validation\PropertyRules;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * A property marked {@see UnknownCodeAsNull} accepts any value of the code
 * grammar, so the enum rule its type infers is removed.
 */
final class UnknownCodeRuleInferrer implements RuleInferrer
{
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        return $property->attributes->has(UnknownCodeAsNull::class) ? $rules->removeType(Enum::class) : $rules;
    }
}
