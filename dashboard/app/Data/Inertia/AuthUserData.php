<?php

declare(strict_types=1);

namespace App\Data\Inertia;

use App\Models\User;
use Spatie\LaravelData\Data;

/** The signed-in user as every page sees it: a whitelist, never the model (AGENTS.md rule 4). */
final class AuthUserData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public bool $emailVerified,
        public bool $isInstanceOwner,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self(
            name: $user->name,
            email: $user->email,
            emailVerified: $user->email_verified_at !== null,
            isInstanceOwner: $user->is_instance_owner,
        );
    }
}
