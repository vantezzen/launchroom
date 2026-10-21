<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\Wire;
use Spatie\LaravelData\Data;

/**
 * NodeState `firewall.meshAllow[]` (docs/05 §3): a mesh-published port is
 * reachable only from these peers' mesh IPs.
 */
final class MeshAllowRule extends Data
{
    /**
     * @param  list<string>  $from
     */
    public function __construct(
        public int $port,
        public array $from,
    ) {}

    public static function fromProtocol(object $wire): self
    {
        return new self(Wire::int($wire, 'port'), Wire::strings($wire, 'from') ?? []);
    }

    /**
     * @return array{port: int, from: list<string>}
     */
    public function toProtocol(): array
    {
        return ['port' => $this->port, 'from' => $this->from];
    }
}
