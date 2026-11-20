<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * One name of the DNS switch as stored on the move (docs/14 §13.4).
 */
final class DnsSwitchEntryData extends Data
{
    /**
     * @param  list<string>  $addresses  where it should point
     * @param  list<string>  $remove  the old server's addresses
     */
    public function __construct(
        /** dashboard | apps | domain */
        public string $kind,
        public string $name,
        public array $addresses,
        public array $remove,
        public ?string $usedBy = null,
    ) {}
}
