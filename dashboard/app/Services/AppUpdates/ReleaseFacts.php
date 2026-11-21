<?php

declare(strict_types=1);

namespace App\Services\AppUpdates;

use Carbon\CarbonImmutable;

/**
 * When an image was published and where its source lives (the OCI source
 * label), as far as the registry says.
 */
final readonly class ReleaseFacts
{
    public function __construct(
        public ?CarbonImmutable $publishedAt,
        public ?string $source,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $at = $data['publishedAt'] ?? null;

        return new self(is_int($at) ? CarbonImmutable::createFromTimestamp($at) : null, is_string($data['source'] ?? null) ? $data['source'] : null);
    }

    /**
     * @return array{publishedAt: int|null, source: string|null}
     */
    public function toArray(): array
    {
        return ['publishedAt' => $this->publishedAt?->getTimestamp(), 'source' => $this->source];
    }
}
