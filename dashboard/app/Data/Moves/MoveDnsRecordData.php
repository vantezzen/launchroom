<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Data\Domains\DnsCheckData;
use Spatie\LaravelData\Data;

/**
 * A DNS record a move asks the user to change (docs/14 §13.2): the
 * dashboard's host, the apps wildcard or a custom domain, which should
 * point at the target's addresses (and no longer at the source's).
 */
final class MoveDnsRecordData extends Data
{
    /**
     * @param  list<string>  $addresses  where it should point
     * @param  list<string>  $remove  addresses to take out (the source's)
     */
    public function __construct(
        /** dashboard | apps | domain */
        public string $kind,
        public string $host,
        public array $addresses,
        public array $remove,
        public ?DnsCheckData $check,
    ) {}
}
