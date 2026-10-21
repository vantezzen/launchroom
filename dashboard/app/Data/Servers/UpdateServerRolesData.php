<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\ServerRole;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * The roles an admin may change after enrollment (docs/02 §2.2): edge,
 * runtime and build. `registry` stays with the control plane and is kept
 * as it is.
 */
final class UpdateServerRolesData extends Data
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public array $roles,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:3'],
            'roles.*' => ['string', 'distinct', Rule::in(array_map(static fn (ServerRole $role): string => $role->value, UpdateServerRolesData::editable()))],
        ];
    }

    /**
     * @return list<ServerRole>
     */
    public static function editable(): array
    {
        return [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build];
    }

    /**
     * @return list<ServerRole>
     */
    public function serverRoles(): array
    {
        return array_values(array_unique(array_map(ServerRole::from(...), $this->roles), SORT_REGULAR));
    }
}
