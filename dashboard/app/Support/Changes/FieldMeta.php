<?php

declare(strict_types=1);

namespace App\Support\Changes;

use App\Data\Attributes\ChangeAffects;
use App\Data\Attributes\Promotion;
use App\Enums\ChangeEffect;
use App\Enums\PromotionClass;
use ReflectionClass;
use ReflectionProperty;

/**
 * Reads #[ChangeAffects] / #[Promotion] from Data properties (docs/06 §6.2:
 * "new settings get the right behaviour by declaring it once").
 */
final class FieldMeta
{
    /** @var array<string, array<string, array{ChangeEffect, PromotionClass}>> */
    private static array $cache = [];

    /**
     * Annotated properties of $class, in declaration order.
     *
     * @param  class-string  $class
     * @return array<string, array{ChangeEffect, PromotionClass}>
     */
    public static function of(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $fields = [];

        foreach (new ReflectionClass($class)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $affects = $property->getAttributes(ChangeAffects::class)[0] ?? null;
            $promotion = $property->getAttributes(Promotion::class)[0] ?? null;

            if ($affects === null) {
                continue;
            }

            $fields[$property->getName()] = [
                $affects->newInstance()->effect,
                $promotion?->newInstance()->class ?? PromotionClass::Structural,
            ];
        }

        return self::$cache[$class] = $fields;
    }

    /**
     * @param  class-string  $class
     */
    public static function promotion(string $class, string $property): PromotionClass
    {
        $attribute = new ReflectionProperty($class, $property)->getAttributes(Promotion::class)[0] ?? null;

        return $attribute?->newInstance()->class ?? PromotionClass::Structural;
    }
}
