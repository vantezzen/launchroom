<?php

declare(strict_types=1);

namespace App\Data\Instance;

use SensitiveParameter;
use Spatie\LaravelData\Data;

/**
 * First-run "create owner" input (docs/08 §3.1, docs/09 R10).
 */
final class CreateOwnerData extends Data
{
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        public string $name,
        public string $email,
        #[SensitiveParameter]
        public string $password,
    ) {}
}
