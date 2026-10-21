<?php

declare(strict_types=1);

namespace App\Data\Teams;

use SensitiveParameter;
use Spatie\LaravelData\Data;

/**
 * Account details for an invitee who doesn't have an account yet.
 */
final class AcceptInvitationData extends Data
{
    public function __construct(
        public string $name,
        #[SensitiveParameter]
        public string $password,
    ) {}
}
