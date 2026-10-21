<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\TeamRole;
use Spatie\LaravelData\Data;

/**
 * A team a token can be bound to, with what the user's role there allows.
 */
final class ApiTeamOptionData extends Data
{
    /**
     * @param  list<ApiAbilityOptionData>  $abilities
     */
    public function __construct(
        public string $id,
        public string $name,
        public TeamRole $role,
        public bool $current,
        public array $abilities,
    ) {}
}
