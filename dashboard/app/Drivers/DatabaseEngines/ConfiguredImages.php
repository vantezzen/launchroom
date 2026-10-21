<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Enums\DatabaseEngine;
use App\Support\Images\ImageReference;
use InvalidArgumentException;

/**
 * Digest-pinned engine images from config (`launchroom.database_engines`).
 */
final readonly class ConfiguredImages
{
    /**
     * @param  array<string, array<string, string>>  $images  engine => version => reference
     */
    public function __construct(
        private array $images,
    ) {}

    public function for(DatabaseEngine $engine, string $version): ImageReference
    {
        $reference = $this->images[$engine->value][$version] ?? null;
        $image = is_string($reference) ? ImageReference::tryParse($reference) : null;

        if (! $image instanceof ImageReference || ! $image->isPinned()) {
            throw new InvalidArgumentException("No digest-pinned image is configured for {$engine->value} {$version}.");
        }

        return $image;
    }

    /**
     * @return list<string>
     */
    public function versions(DatabaseEngine $engine): array
    {
        return array_map(strval(...), array_keys($this->images[$engine->value] ?? []));
    }
}
