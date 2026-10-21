<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Models\TeamInvitation;
use SensitiveParameter;

/**
 * A new invitation and its token. The token exists only here and in the
 * e-mail; never serialize this object.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public TeamInvitation $invitation,
        #[SensitiveParameter]
        public string $token,
    ) {}
}
