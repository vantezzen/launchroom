<?php

declare(strict_types=1);

namespace App\Data\Previews;

use Spatie\LaravelData\Data;

/**
 * `previews.data`: what the preview's workflow remembers between steps.
 */
final class PreviewStateData extends Data
{
    /**
     * @param  array<string, PreviewDatabaseStateData>  $databases  by service public id
     */
    public function __construct(
        public array $databases = [],
        /** The head commit whose config file was applied, and its error. */
        public ?string $configSha = null,
        public ?string $configError = null,
        /** The head commit last deployed. */
        public ?string $deployedSha = null,
        public ?PreviewClosingData $pendingClose = null,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function changed(array $changes): self
    {
        return self::from([...$this->toArray(), ...$changes]);
    }
}
