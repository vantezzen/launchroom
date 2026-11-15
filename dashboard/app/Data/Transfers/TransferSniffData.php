<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use Spatie\LaravelData\Data;

/**
 * `data_transfers.sniff`: what the sniffer found in an upload (docs/14
 * §12.4): the dump's engine family and versions, or an archive's entries.
 */
final class TransferSniffData extends Data
{
    /**
     * @param  list<string>  $entries  at most 200
     */
    public function __construct(
        public ?string $family = null,
        public ?string $serverVersion = null,
        public ?string $toolVersion = null,
        public array $entries = [],
        public bool $entriesComplete = false,
        public ?string $topFolder = null,
    ) {}
}
