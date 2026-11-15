<?php

declare(strict_types=1);

namespace App\Data\Attributes;

use Attribute;
use BackedEnum;
use Spatie\LaravelData\Attributes\GetsCast;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;

/**
 * An explanation code from launchroomd (a hint, a failure or capability
 * reason) on an enum-typed property: an unknown value reads as null instead of
 * refusing the whole report or completion (docs/03 §10, "Unknown codes"). The
 * value is checked by the code grammar only; {@see UnknownCodeRuleInferrer}
 * drops the enum rule the type would add.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class UnknownCodeAsNull extends Regex implements Cast, GetsCast
{
    public const string GRAMMAR = '/^[a-z][a-z0-9_]{0,63}$/D';

    public function __construct()
    {
        parent::__construct(self::GRAMMAR);
    }

    public function get(): Cast
    {
        return $this;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  CreationContext<Data>  $context
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): ?BackedEnum
    {
        if ($value instanceof BackedEnum) {
            return $value;
        }

        /** @var class-string<BackedEnum>|null $enum */
        $enum = $property->type->type->findAcceptedTypeForBaseType(BackedEnum::class);

        return is_string($value) && $enum !== null ? $enum::tryFrom($value) : null;
    }
}
