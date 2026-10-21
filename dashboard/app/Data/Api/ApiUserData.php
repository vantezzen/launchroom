<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

final class ApiUserData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
