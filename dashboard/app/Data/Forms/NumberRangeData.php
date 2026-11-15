<?php

declare(strict_types=1);

namespace App\Data\Forms;

use LogicException;
use ReflectionParameter;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * The bounds a form field accepts, read from the `#[Min]`/`#[Max]` rules of
 * the Data parameter that validates it, so the form and validation can't
 * drift apart.
 */
final class NumberRangeData extends Data
{
    public function __construct(
        public int $min,
        public int $max,
    ) {}

    /**
     * @param  class-string<Data>  $class
     */
    public static function of(string $class, string $parameter): self
    {
        [$min, $max] = self::bounds($class, $parameter);

        if (! is_int($min) || ! is_int($max)) {
            throw new LogicException("{$class}::\${$parameter} has no integer bounds.");
        }

        return new self($min, $max);
    }

    /**
     * @param  class-string<Data>  $class
     * @return array{int|float, int|float}
     */
    public static function bounds(string $class, string $parameter): array
    {
        $reflection = new ReflectionParameter([$class, '__construct'], $parameter);

        return [self::bound($reflection, Min::class), self::bound($reflection, Max::class)];
    }

    /**
     * @param  class-string<Min|Max>  $attribute
     */
    private static function bound(ReflectionParameter $parameter, string $attribute): int|float
    {
        $value = ($parameter->getAttributes($attribute)[0] ?? null)?->newInstance()->parameters()[0] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new LogicException("{$parameter->getDeclaringClass()?->getName()}::\${$parameter->getName()} has no numeric {$attribute::keyword()} rule.");
        }

        return $value;
    }
}
