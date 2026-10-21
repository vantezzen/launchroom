<?php

declare(strict_types=1);

namespace App\Data\Services;

/**
 * `services.source` (docs/07): a union of GitSource | ImageSource |
 * DatabaseSource, stored as JSON with a `type` discriminator by
 * App\Casts\ServiceSourceCast.
 */
interface ServiceSource
{
    /** Discriminator stored in the JSON column. */
    public function type(): string;

    /**
     * The JSON shape without the discriminator.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
