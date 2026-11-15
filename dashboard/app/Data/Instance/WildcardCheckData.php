<?php

declare(strict_types=1);

namespace App\Data\Instance;

use App\Support\Hostname;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * The live DNS check of a platform domain: `apps.example.com` or
 * `*.apps.example.com`.
 */
final class WildcardCheckData extends Data
{
    public function __construct(
        #[Max(240)]
        public string $domain,
    ) {}

    public function hostname(): string
    {
        return Hostname::normalize(preg_replace('/^\*\./', '', trim($this->domain)) ?? '');
    }
}
