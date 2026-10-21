<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Enums\TeamRole;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

final class InviteMemberData extends Data
{
    public function __construct(
        #[Email, Max(255)]
        public string $email,
        public TeamRole $role,
    ) {}
}
