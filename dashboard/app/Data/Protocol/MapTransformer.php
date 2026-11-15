<?php

declare(strict_types=1);

namespace App\Data\Protocol;

use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

/**
 * A protocol map (`map[K]V` in launchroomd): encoded as a JSON object even
 * when empty, since `[]` doesn't decode into a Go map (ProtocolDocument).
 */
final class MapTransformer implements Transformer
{
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): mixed
    {
        return is_array($value) ? (object) $value : $value;
    }
}
