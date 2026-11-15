<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Data\Domains\DnsCheckData;
use Spatie\LaravelData\Data;

/**
 * One name that must point at the new control plane after a move or a
 * recovery: the dashboard, the apps wildcard or a custom domain.
 */
final class CutoverDnsRowData extends Data
{
    public function __construct(
        /** "Dashboard", "Apps" or "Domain". */
        public string $label,
        public string $host,
        /** Null until checked. */
        public ?DnsCheckData $check,
    ) {}
}
