<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Data;

final class RegistryInfo extends Data
{
    public function __construct(
        public string $address,
        public ?string $forwardTo = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toProtocol(): array
    {
        return array_filter(['address' => $this->address, 'forwardTo' => $this->forwardTo], is_string(...));
    }
}
