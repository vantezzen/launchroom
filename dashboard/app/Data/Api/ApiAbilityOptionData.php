<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\ApiAbility;
use Spatie\LaravelData\Data;

/**
 * One ability in the create-token dialog and the CLI approval page.
 * `available`: the user's role in the team can use it.
 */
final class ApiAbilityOptionData extends Data
{
    public function __construct(
        public ApiAbility $value,
        public string $label,
        public string $description,
        public bool $available,
        public bool $default,
    ) {}
}
