<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\Wire;
use Spatie\LaravelData\Data;

/**
 * NodeState `networks[]`: one bridge per environment present on the server.
 */
final class NetworkSpec extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $subnet,
    ) {}

    public static function fromProtocol(object $wire): self
    {
        return new self(Wire::string($wire, 'id'), Wire::string($wire, 'name'), Wire::string($wire, 'subnet'));
    }

    /**
     * @return array<string, string>
     */
    public function toProtocol(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'subnet' => $this->subnet];
    }
}
