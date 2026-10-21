<?php

declare(strict_types=1);

namespace App\Data\Variables;

use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

/**
 * An environment a variable can target.
 */
final class EnvironmentOptionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public EnvironmentKind $kind,
    ) {}
}
