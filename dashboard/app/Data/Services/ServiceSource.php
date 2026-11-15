<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Enums\ServiceSourceType;

/**
 * `services.source` (docs/07): a union of GitSource | ImageSource |
 * DatabaseSource, stored as JSON with a `type` discriminator.
 */
interface ServiceSource
{
    public function type(): ServiceSourceType;

    /**
     * The JSON shape without the discriminator.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
