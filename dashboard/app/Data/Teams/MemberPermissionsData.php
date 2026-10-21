<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Enums\Permission;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * The complete set of a developer's individual grants (docs/09 R33).
 */
final class MemberPermissionsData extends Data
{
    /**
     * @param  list<string>|null  $permissions  null/absent = none
     */
    public function __construct(
        #[Max(8)]
        public ?array $permissions = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return ['permissions.*' => ['string', Rule::in(array_map(static fn (Permission $permission): string => $permission->value, Permission::grantable()))]];
    }

    /**
     * @return list<Permission>
     */
    public function toPermissions(): array
    {
        return array_map(Permission::from(...), $this->permissions ?? []);
    }
}
