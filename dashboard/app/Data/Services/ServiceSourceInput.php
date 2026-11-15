<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Casts\Uncastable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;

/**
 * Lets a Data property typed ServiceSource take the stored array shape too
 * (`['type' => 'image', 'reference' => …]`), validated: sources built in
 * code are validated by their builder.
 */
final class ServiceSourceInput implements Cast
{
    /**
     * @param  CreationContext<Data>  $context
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): mixed
    {
        return match (true) {
            $value instanceof ServiceSource => $value,
            is_array($value) => ServiceSources::validated($value),
            default => Uncastable::create(),
        };
    }
}
