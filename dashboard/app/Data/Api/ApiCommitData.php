<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

final class ApiCommitData extends Data
{
    public function __construct(
        public string $sha,
        public string $shortSha,
        public ?string $message,
        public ?string $author,
        public ?string $ref,
    ) {}
}
