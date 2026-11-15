<?php

declare(strict_types=1);

namespace App\Data\Attributes;

use App\Support\PublicId;
use Attribute;
use Spatie\LaravelData\Attributes\Validation\Regex;

/**
 * Validates a public id with one of the given prefixes (`#[PublicIdOf('env')]`),
 * using the protocol's ULID grammar (PublicId::regex).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class PublicIdOf extends Regex
{
    public function __construct(string $prefix, string ...$more)
    {
        parent::__construct(PublicId::regex($prefix, ...$more));
    }
}
