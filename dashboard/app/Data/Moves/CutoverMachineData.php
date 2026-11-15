<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Models\Server;
use Spatie\LaravelData\Data;

/**
 * The machine launchroom moved from or to, on the move checklist.
 */
final class CutoverMachineData extends Data
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $name,
        public array $addresses,
    ) {}

    public static function fromServer(Server $server): self
    {
        return new self($server->name, array_values(array_filter([$server->public_ipv4, $server->public_ipv6])));
    }
}
