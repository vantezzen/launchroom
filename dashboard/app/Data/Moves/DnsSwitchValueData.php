<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * One value of a DNS record to set after a move: an A or AAAA record's address.
 */
final class DnsSwitchValueData extends Data
{
    public function __construct(
        /** A | AAAA */
        public string $type,
        public string $value,
    ) {}

    /**
     * @param  list<string>  $addresses
     * @return list<self>
     */
    public static function fromAddresses(array $addresses): array
    {
        return array_map(static fn (string $address): self => new self(str_contains($address, ':') ? 'AAAA' : 'A', $address), $addresses);
    }
}
