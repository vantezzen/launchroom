<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Enums\TeamRole;
use Spatie\LaravelData\Data;

final class ChangeMemberRoleData extends Data
{
    public function __construct(
        public TeamRole $role,
    ) {}
}
