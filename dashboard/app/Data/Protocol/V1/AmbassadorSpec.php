<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\Wire;
use Spatie\LaravelData\Data;

/**
 * NodeState `ambassadors[]` (docs/05 §3): one listener of the server's
 * ambassador, `alias:port` on an environment network, forwarding to the
 * mesh-published replicas of that service on other servers.
 */
final class AmbassadorSpec extends Data
{
    /**
     * @param  list<string>  $upstreams  `<mesh ip>:<mesh port>`
     */
    public function __construct(
        public string $network,
        public string $alias,
        public int $port,
        public array $upstreams,
    ) {}

    public static function fromProtocol(object $wire): self
    {
        return new self(Wire::string($wire, 'network'), Wire::string($wire, 'alias'), Wire::int($wire, 'port'), Wire::strings($wire, 'upstreams') ?? []);
    }

    /**
     * @return array{network: string, alias: string, port: int, upstreams: list<string>}
     */
    public function toProtocol(): array
    {
        return ['network' => $this->network, 'alias' => $this->alias, 'port' => $this->port, 'upstreams' => $this->upstreams];
    }
}
