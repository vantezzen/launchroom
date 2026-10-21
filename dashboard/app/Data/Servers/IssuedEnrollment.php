<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Models\ServerEnrollment;
use SensitiveParameter;

/**
 * A new enrollment and its join token. The token is shown once and never
 * stored; never serialize this object.
 */
final readonly class IssuedEnrollment
{
    public function __construct(
        public ServerEnrollment $enrollment,
        #[SensitiveParameter]
        public string $token,
    ) {}
}
