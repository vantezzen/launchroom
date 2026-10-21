<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\Wire;
use Spatie\LaravelData\Data;

/**
 * NodeState `volumes[]`: named, launchroom-labelled volumes (never deleted
 * because they leave this list; see VolumeDeletion).
 */
final class VolumeSpec extends Data
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    public static function fromProtocol(object $wire): self
    {
        return new self(Wire::string($wire, 'id'), Wire::string($wire, 'name'));
    }

    /**
     * @return array<string, string>
     */
    public function toProtocol(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
