<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\TeamRole;
use Spatie\LaravelData\Data;

/**
 * The team a token is bound to, with the owner's role in it.
 */
final class ApiTeamData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?TeamRole $role = null,
    ) {}
}
