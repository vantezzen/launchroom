<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Data;

final class SystemContainer extends Data
{
    public function __construct(
        public bool $enabled,
        public ?string $image = null,
    ) {}

    /**
     * @return array<string, bool|string>
     */
    public function toProtocol(): array
    {
        return $this->image === null ? ['enabled' => $this->enabled] : ['enabled' => $this->enabled, 'image' => $this->image];
    }
}
