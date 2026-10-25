<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * The inventory of a service's live image (docs/22 §11).
 */
final class ServiceInventoryResource extends Data
{
    /**
     * @param  array<string, string>|null  $distro  os-release `id` and `versionId`
     * @param  list<InventoryComponentResource>  $components
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $id,
        public string $image,
        public string $platform,
        /** `pending`, `ready` or `failed`. */
        public string $status,
        /** `built` (lockfiles set scopes) or `registry`. */
        public string $source,
        public ?array $distro,
        public ?string $scannedAt,
        public bool $truncated,
        public array $notes,
        public ?string $error,
        public array $components,
    ) {}
}
