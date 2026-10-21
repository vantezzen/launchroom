<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * A DNS record the user creates at their DNS provider for a custom domain.
 */
final class DnsRecordData extends Data
{
    public function __construct(
        /** `A`, `AAAA` or `CNAME`. */
        public string $type,
        public string $name,
        public string $value,
    ) {}
}
