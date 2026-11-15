<?php

declare(strict_types=1);

namespace App\Data\Inertia;

use App\Models\User;
use Spatie\LaravelData\Data;

final class AuthData extends Data
{
    public function __construct(
        public ?AuthUserData $user,
    ) {}

    public static function for(mixed $user): self
    {
        return new self($user instanceof User ? AuthUserData::fromUser($user) : null);
    }
}
